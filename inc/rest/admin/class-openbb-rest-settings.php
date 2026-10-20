<?php
/**
 * Plugin settings and site-local maintenance actions for the Settings screen.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Settings extends OPENBB_REST_Controller {

	const DELETE_CONFIRMATION = 'DELETE ALL DATA';

	public function register_routes(): void {
		$can_manage = static function () {
			return self::authorize( 'openbb_manage_settings' );
		};

		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'test_mode' => array( 'type' => 'boolean' ),
						'general'   => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'allow_signups', 'require_member_approval', 'membership_fee_type', 'membership_fee_amount', 'membership_fee_order_interval' ),
							'properties'           => array(
								'allow_signups'                 => array( 'type' => 'boolean' ),
								'require_member_approval'       => array( 'type' => 'boolean' ),
								'membership_fee_type'           => array(
									'type' => 'string',
									'enum' => array( 'one_time', 'recurring' ),
								),
								'membership_fee_amount'         => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 9007199254740991,
								),
								'membership_fee_order_interval' => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 9007199254740991,
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/demo-data',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'populate_demo_data' ),
				'permission_callback' => $can_manage,
			)
		);

		$can_manage_fields = static function () {
			return self::authorize( 'openbb_manage_profile_fields' );
		};

		$can_manage_rules = static function () {
			return self::authorize( 'openbb_manage_rules' );
		};
		$rule_content = array(
			'type'     => 'array',
			'required' => true,
			'items'    => array(
				'type'                 => 'object',
				'required'             => array( 'default' ),
				'properties'           => array( 'default' => array( 'type' => 'string' ) ),
				'additionalProperties' => array( 'type' => 'string' ),
			),
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/settings/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rules' ),
					'permission_callback' => $can_manage_rules,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_rules' ),
					'permission_callback' => $can_manage_rules,
					'args'                => array(
						'content'  => $rule_content,
						'revision' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);
		foreach ( array( 'publish', 'discard' ) as $action ) {
			register_rest_route(
				self::API_NAMESPACE,
				'/settings/rules/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'publish' === $action ? 'publish_rules' : 'discard_rules' ),
					'permission_callback' => $can_manage_rules,
					'args'                => array_merge(
						array( 'revision' => array( 'type' => 'string', 'required' => true ) ),
						'publish' === $action ? array( 'requires_reacceptance' => array( 'type' => 'boolean', 'required' => true ) ) : array()
					),
				)
			);
		}

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/profile-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile_fields' ),
					'permission_callback' => $can_manage_fields,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile_fields' ),
					'permission_callback' => $can_manage_fields,
					'args'                => array(
						'revision' => array(
							'type'     => 'integer',
							'minimum'  => 0,
							'required' => true,
						),
						'fields'   => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'object' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/profile-fields/(?P<key>[a-z][a-z0-9_]{0,39})/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge_profile_field' ),
				'permission_callback' => $can_manage_fields,
				'args'                => array(
					'confirmation' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/delete-all-data',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_all_data' ),
				'permission_callback' => static function () {
					return self::authorize( 'manage_options' );
				},
				'args'                => array(
					'confirmation' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( self::prepare_settings() );
	}

	public function get_rules(): WP_REST_Response {
		$versions = array_map(
			static function ( OPENBB_Rules_Version $version ): array {
				$author = get_userdata( $version->published_by );
				return array(
					'id'                    => $version->id,
					'content'               => $version->content,
					'published_at'          => mysql_to_rfc3339( $version->published_at ),
					'published_by'          => $author ? $author->display_name : __( 'Deleted user', 'open-bulk-buying' ),
					'requires_reacceptance' => $version->requires_reacceptance,
				);
			},
			OPENBB_Rules::get_versions()
		);

		return rest_ensure_response(
			array(
				'draft'       => OPENBB_Rules::get_draft(),
				'revision'    => self::rules_revision(),
				'unpublished' => OPENBB_Rules::has_unpublished_changes(),
				'versions'    => $versions,
			)
		);
	}

	private static function rules_revision(): string {
		$latest = OPENBB_Rules::get_latest();
		return md5( OPENBB_Rules::hash_content( OPENBB_Rules::get_draft() ) . ':' . ( $latest ? $latest->id : 0 ) );
	}

	private static function check_rules_revision( WP_REST_Request $request ): ?WP_Error {
		if ( self::rules_revision() !== $request['revision'] ) {
			return new WP_Error( 'openbb_rules_stale', __( 'The rules changed since you loaded them. Reload the rules before saving.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		return null;
	}

	public function save_rules( WP_REST_Request $request ) {
		$error = self::check_rules_revision( $request );
		if ( $error ) {
			return $error;
		}
		if ( ! OPENBB_Rules::save_draft( $request['content'] ) ) {
			return new WP_Error( 'openbb_rules_save_failed', __( 'The draft could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return $this->get_rules();
	}

	public function publish_rules( WP_REST_Request $request ) {
		$error = self::check_rules_revision( $request );
		if ( $error ) {
			return $error;
		}
		$result = OPENBB_Rules::publish( $request['requires_reacceptance'], get_current_user_id() );
		return is_wp_error( $result ) ? self::with_status( $result, 'openbb_rules_empty' === $result->get_error_code() ? 400 : 500 ) : $this->get_rules();
	}

	public function discard_rules( WP_REST_Request $request ) {
		$error = self::check_rules_revision( $request );
		if ( $error ) {
			return $error;
		}
		if ( ! OPENBB_Rules::discard_draft() ) {
			return new WP_Error( 'openbb_rules_discard_failed', __( 'The draft could not be discarded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return $this->get_rules();
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		if ( $request->has_param( 'general' ) ) {
			$general = array_merge( OPENBB_Settings::get_general(), $request->get_param( 'general' ) );
			if ( OPENBB_Settings::get_general() !== $general
				&& ! update_option( OPENBB_Settings::GENERAL_OPTION, $general, false ) ) {
				return new WP_Error( 'openbb_settings_save_failed', __( 'The settings could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}
		}

		if ( $request->has_param( 'test_mode' ) ) {
			$enabled = (bool) $request->get_param( 'test_mode' );
			$saved   = OPENBB_Maintenance::is_test_mode() === $enabled
				|| update_option( OPENBB_Maintenance::TEST_MODE_OPTION, $enabled, false );
			if ( ! $saved ) {
				return new WP_Error( 'openbb_settings_save_failed', __( 'The settings could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( self::prepare_settings() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function populate_demo_data() {
		$result = OPENBB_Maintenance::populate_test_data();
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 'openbb_test_mode_disabled' === $result->get_error_code() || 'openbb_demo_product_conflict' === $result->get_error_code() ? 409 : 500 );
		}

		return rest_ensure_response( array( 'populated' => true ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_all_data( WP_REST_Request $request ) {
		if ( self::DELETE_CONFIRMATION !== $request->get_param( 'confirmation' ) ) {
			return self::invalid(
				/* translators: %s: the confirmation phrase. */
				sprintf( __( 'Type %s to confirm.', 'open-bulk-buying' ), self::DELETE_CONFIRMATION ),
				'openbb_delete_unconfirmed'
			);
		}

		$result = OPENBB_Maintenance::delete_all_site_data();
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 500 );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function get_profile_fields(): WP_REST_Response {
		return rest_ensure_response( self::prepare_profile_fields( OPENBB_Profile_Fields::get_schema() ) );
	}

	/**
	 * Replaces the whole field list; 409 if someone saved since `revision`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile_fields( WP_REST_Request $request ) {
		$schema = OPENBB_Profile_Fields::save_schema( (array) $request['fields'], (int) $request['revision'] );

		return is_wp_error( $schema ) ? $schema : rest_ensure_response( self::prepare_profile_fields( $schema ) );
	}

	/**
	 * Deletes an archived field and its values; the confirmation is the field key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_profile_field( WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( $key !== $request->get_param( 'confirmation' ) ) {
			return self::invalid(
				/* translators: %s: the field key. */
				sprintf( __( 'Type %s to confirm.', 'open-bulk-buying' ), $key ),
				'openbb_purge_unconfirmed'
			);
		}

		$schema = OPENBB_Profile_Fields::purge_field( $key );

		return is_wp_error( $schema ) ? $schema : rest_ensure_response( self::prepare_profile_fields( $schema ) );
	}

	/**
	 * The schema plus the keys the editor must avoid when it suggests a key.
	 */
	private static function prepare_profile_fields( array $schema ): array {
		$schema['reserved_keys'] = OPENBB_Profile_Fields::reserved_keys();

		return $schema;
	}

	private static function prepare_settings(): array {
		return array(
			'test_mode' => OPENBB_Maintenance::is_test_mode(),
			'general'   => OPENBB_Settings::get_general(),
		);
	}

	private static function with_status( WP_Error $error, int $status ): WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$error->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) ) );
		}

		return $error;
	}
}
