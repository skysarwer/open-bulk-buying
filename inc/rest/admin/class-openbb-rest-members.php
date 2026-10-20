<?php
/**
 * Co-op members: WordPress users with a co-op role on this site.
 *
 * Email, login and password stay with WordPress's own user screens.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Members extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$can_view = static function () {
			return self::authorize( 'openbb_view_members' );
		};
		$can_edit = static function () {
			return self::authorize( 'openbb_edit_members' );
		};

		register_rest_route(
			self::API_NAMESPACE,
			'/members',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_view,
					'args'                => self::collection_args(
						array( 'name', 'email', 'registered' ),
						'asc',
						array(
							'coop_role' => array(
								'type'    => 'array',
								'items'   => array(
									'type' => 'string',
									'enum' => array_keys( OPENBB_Access::coop_roles() ),
								),
								'default' => array(),
							),
							'profile'   => array(
								'description'          => __( 'Profile field key => values. Matches any of a field\'s values, and all fields.', 'open-bulk-buying' ),
								'type'                 => 'object',
								'additionalProperties' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'default'              => array(),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can_edit,
					'args'                => array(
						'user_id'    => array(
							'description' => __( 'An existing user to make a member. Leave out to create a new user from `account`.', 'open-bulk-buying' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'account'    => array(
							'description' => __( 'New user: email, first_name, last_name, phone, language.', 'open-bulk-buying' ),
							'type'        => 'object',
						),
						'send_email' => array(
							'description' => __( 'New user: send WordPress\'s set-your-password email.', 'open-bulk-buying' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'coop_role'  => array(
							'type'    => 'string',
							'default' => OPENBB_Access::ROLE_MEMBER,
							'enum'    => array_keys( OPENBB_Access::coop_roles() ),
						),
						'profile'    => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/profile-fields',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profile_fields' ),
				'permission_callback' => $can_view,
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/members/candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_candidates' ),
				'permission_callback' => $can_edit,
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/members/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_view,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_edit,
					'args'                => array(
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'phone'      => array( 'type' => 'string' ),
						'language'   => array( 'type' => 'string' ),
						'coop_role'  => array(
							'type' => 'string',
							'enum' => array_keys( OPENBB_Access::coop_roles() ),
						),
						'profile'    => array( 'type' => 'object' ),
					),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$role_key = openbb_member_meta_key( 'coop_role' );
		$roles    = array_values( array_intersect( (array) $request['coop_role'], array_keys( OPENBB_Access::coop_roles() ) ) );
		$per_page = (int) $request['per_page'];

		$args = array(
			'number'      => $per_page,
			'paged'       => (int) $request['page'],
			'order'       => strtoupper( $request['order'] ),
			'orderby'     => array(
				'name'       => 'display_name',
				'email'      => 'user_email',
				'registered' => 'user_registered',
			)[ $request['orderby'] ],
			'count_total' => true,
			'blog_id'     => 0,
			'meta_query'  => array(
				$roles
					? array(
						'key'     => $role_key,
						'value'   => $roles,
						'compare' => 'IN',
					)
					: array(
						'key'     => $role_key,
						'value'   => '',
						'compare' => '!=',
					),
			),
		);
		if ( '' !== $request['search'] ) {
			$args['search']         = '*' . $request['search'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}
		$args['meta_query'] = array_merge( $args['meta_query'], self::profile_clauses( (array) $request['profile'] ) );

		$query   = new WP_User_Query( $args );
		$members = array_filter(
			array_map(
				static function ( WP_User $user ): ?OPENBB_Member {
					return OPENBB_Member::get( $user->ID );
				},
				$query->get_results()
			)
		);

		return self::paginated( $this->prepare_members( $members ), (int) $query->get_total(), $per_page );
	}

	/**
	 * meta_query clauses for the `profile` filter. Unknown (e.g. purged) keys are ignored
	 * so saved views keep working.
	 *
	 * @param array $filters Field key => values.
	 * @return array[]
	 */
	private static function profile_clauses( array $filters ): array {
		$fields  = OPENBB_Profile_Fields::get_fields_for_context( OPENBB_Profile_Fields::CONTEXT_STAFF );
		$clauses = array();
		foreach ( $filters as $key => $values ) {
			$field  = $fields[ $key ] ?? null;
			$values = array_values( array_unique( array_map( 'strval', (array) $values ) ) );
			if ( ! $field || ! $values ) {
				continue;
			}
			if ( OPENBB_Profile_Fields::TYPE_BOOLEAN === $field['type'] ) {
				$values = array_values( array_unique( array_map( 'rest_sanitize_boolean', $values ) ) );
				if ( count( $values ) > 1 ) {
					continue;
				}
			}

			// An explicit OR group also makes WP_User_Query select DISTINCT users, so
			// members with several matching options aren't listed twice.
			$group = array( 'relation' => 'OR' );
			foreach ( $values as $value ) {
				$group[] = OPENBB_Profile_Fields::meta_query_clause( $field, $value );
			}
			$clauses[] = $group;
		}

		return $clauses;
	}

	public function get_item( WP_REST_Request $request ) {
		$member = OPENBB_Member::get( (int) $request['id'] );
		if ( ! $member ) {
			return self::not_found( __( 'Member not found.', 'open-bulk-buying' ) );
		}

		$item           = $this->prepare_member( $member );
		$item['orders'] = current_user_can( 'openbb_view_orders' ) ? $this->member_orders( $member->user_id ) : array();

		return rest_ensure_response( $item );
	}

	/**
	 * Profile field definitions for the Members screen. Archived fields are included,
	 * flagged, so members' existing values can be shown read-only.
	 */
	public function get_profile_fields(): WP_REST_Response {
		return rest_ensure_response( array_values( OPENBB_Profile_Fields::get_fields_for_context( OPENBB_Profile_Fields::CONTEXT_STAFF ) ) );
	}

	/**
	 * Gives an existing user who isn't a member a co-op role, or creates a new user (`account`).
	 */
	public function create_item( WP_REST_Request $request ) {
		if ( $request->has_param( 'account' ) && ! $request->has_param( 'user_id' ) ) {
			return $this->create_user( $request );
		}
		if ( ! $request->has_param( 'user_id' ) ) {
			return new WP_Error( 'rest_missing_callback_param', __( 'Send either user_id (existing user) or account (new user).', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$user_id = (int) $request['user_id'];
		if ( ! get_userdata( $user_id ) || ! $this->is_site_user( $user_id ) ) {
			return self::not_found( __( 'User not found.', 'open-bulk-buying' ) );
		}
		if ( '' !== OPENBB_Access::get_coop_role( $user_id ) ) {
			return new WP_Error( 'openbb_already_member', __( 'This person already has a co-op role.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		// Check the profile first so an invalid one doesn't leave a half-added member.
		$profile = (array) $request['profile'];
		$valid   = OPENBB_Profile_Fields::validate_values( $user_id, $profile, OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$result = OPENBB_Access::set_coop_role( $user_id, (string) $request['coop_role'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 'openbb_forbidden' === $result->get_error_code() ? 403 : 400 ) );
			return $result;
		}

		$saved = OPENBB_Profile_Fields::set_values( $user_id, $profile, OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = rest_ensure_response( $this->prepare_member( OPENBB_Member::get( $user_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	private function create_user( WP_REST_Request $request ) {
		$created = OPENBB_Signup::create_member(
			(array) $request['account'],
			(array) $request['profile'],
			OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE,
			array(
				'coop_role'  => (string) $request['coop_role'],
				'send_email' => (bool) $request['send_email'],
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$item               = $this->prepare_member( OPENBB_Member::get( $created['user_id'] ) );
		$item['email_sent'] = $created['email_sent'];
		$response           = rest_ensure_response( $item );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( WP_REST_Request $request ) {
		$member = OPENBB_Member::get( (int) $request['id'] );
		if ( ! $member ) {
			return self::not_found( __( 'Member not found.', 'open-bulk-buying' ) );
		}

		$details = array_intersect_key( $request->get_params(), array_flip( array( 'first_name', 'last_name', 'phone', 'language' ) ) );
		$profile = $request->has_param( 'profile' ) ? (array) $request['profile'] : array();
		if ( $details || $profile ) {
			$allowed = self::authorize( 'openbb_edit_member', $member->user_id );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}

		// Validate everything before writing anything, so a 400 doesn't leave a partial save.
		if ( isset( $details['language'] ) ) {
			$details['language'] = sanitize_key( (string) $details['language'] );
			$languages           = openbb_languages();
			if ( '' !== $details['language'] && $languages && ! in_array( $details['language'], $languages, true ) ) {
				return self::invalid( __( 'Choose one of the site\'s languages.', 'open-bulk-buying' ) );
			}
		}
		if ( $profile ) {
			$valid = OPENBB_Profile_Fields::validate_values( $member->user_id, $profile, OPENBB_Profile_Fields::CONTEXT_STAFF );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		if ( $details ) {
			$user = array( 'ID' => $member->user_id );
			foreach ( array( 'first_name', 'last_name' ) as $field ) {
				if ( isset( $details[ $field ] ) ) {
					$user[ $field ] = sanitize_text_field( (string) $details[ $field ] );
				}
			}
			if ( count( $user ) > 1 ) {
				$updated = wp_update_user( wp_slash( $user ) );
				if ( is_wp_error( $updated ) ) {
					$updated->add_data( array( 'status' => 500 ) );
					return $updated;
				}
			}
			foreach ( array( 'phone', 'language' ) as $field ) {
				if ( isset( $details[ $field ] ) && ! openbb_update_member_field( $member->user_id, $field, sanitize_text_field( (string) $details[ $field ] ) ) ) {
					return new WP_Error( 'openbb_member_save_failed', __( 'The member could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
				}
			}
		}

		if ( $profile ) {
			$saved = OPENBB_Profile_Fields::set_values( $member->user_id, $profile, OPENBB_Profile_Fields::CONTEXT_STAFF );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( $request->has_param( 'coop_role' ) && $request['coop_role'] !== $member->coop_role ) {
			$result = OPENBB_Access::set_coop_role( $member->user_id, (string) $request['coop_role'] );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 'openbb_forbidden' === $result->get_error_code() ? 403 : 400 ) );
				return $result;
			}
		}

		$saved = OPENBB_Member::get( $member->user_id );
		if ( ! $saved ) {
			return rest_ensure_response(
				array(
					'id'      => $member->user_id,
					'removed' => true,
				)
			);
		}

		return rest_ensure_response( $this->prepare_member( $saved ) );
	}

	/**
	 * Users of this site without a co-op role, for the "Add member" picker.
	 */
	public function get_candidates( WP_REST_Request $request ): WP_REST_Response {
		$role_key = openbb_member_meta_key( 'coop_role' );
		$args     = array(
			'number'     => 20,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => $role_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => $role_key,
					'value' => '',
				),
			),
		);
		if ( '' !== $request['search'] ) {
			$args['search']         = '*' . $request['search'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$users = ( new WP_User_Query( $args ) )->get_results();

		return rest_ensure_response(
			array_map(
				static function ( WP_User $user ): array {
					return array(
						'id'    => $user->ID,
						'name'  => $user->display_name,
						'email' => $user->user_email,
					);
				},
				$users
			)
		);
	}

	private function is_site_user( int $user_id ): bool {
		return ! is_multisite() || is_user_member_of_blog( $user_id );
	}

	/**
	 * One member, as in lists.
	 */
	private function prepare_member( OPENBB_Member $member ): array {
		return $this->prepare_members( array( $member ) )[0];
	}

	/**
	 * Members with their profile field values (options as arrays of keys).
	 *
	 * @param OPENBB_Member[] $members
	 */
	private function prepare_members( array $members ): array {
		global $wpdb;

		$counts = array();
		$ids    = wp_list_pluck( $members, 'user_id' );
		if ( $ids && current_user_can( 'openbb_view_orders' ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, COUNT(*) AS orders_count, MAX(submitted_at) AS last_order_at
					FROM {$wpdb->openbb_orders}
					WHERE status != %s AND user_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')
					GROUP BY user_id',
					...array_merge( array( OPENBB_Order::STATUS_CANCELLED ), $ids )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$counts[ (int) $row['user_id'] ] = $row;
			}
		}

		$role_labels  = OPENBB_Access::role_labels();
		$grant_labels = OPENBB_Access::grant_labels();
		$items        = array();
		foreach ( $members as $member ) {
			$assignable = array();
			foreach ( array_keys( $role_labels ) as $role ) {
				if ( $role === $member->coop_role || current_user_can( 'openbb_set_coop_role', $member->user_id, $role ) ) {
					$assignable[] = $role;
				}
			}
			$grants = OPENBB_Access::get_grants( $member->user_id );

			$items[] = array(
				'id'               => $member->user_id,
				'display_name'     => $member->display_name,
				'first_name'       => $member->first_name,
				'last_name'        => $member->last_name,
				'email'            => $member->email,
				'phone'            => (string) $member->phone,
				'language'         => (string) $member->language,
				'coop_role'        => $member->coop_role,
				'coop_role_label'  => $role_labels[ $member->coop_role ] ?? $member->coop_role,
				'grants'           => $grants,
				'grant_labels'     => array_values( array_intersect_key( $grant_labels, array_flip( $grants ) ) ),
				'joined_at'        => self::to_local_datetime( $member->joined_at ),
				'rules_accepted'   => $member->has_accepted_rules( OPENBB_Rules::get_required_version_id() ),
				'orders_count'     => (int) ( $counts[ $member->user_id ]['orders_count'] ?? 0 ),
				'last_order_at'    => self::to_local_datetime( $counts[ $member->user_id ]['last_order_at'] ?? null ),
				'can_edit'         => current_user_can( 'openbb_edit_member', $member->user_id ),
				'assignable_roles' => $assignable,
				'profile_url'      => current_user_can( 'edit_user', $member->user_id ) ? get_edit_user_link( $member->user_id ) : '',
				'profile'          => (object) OPENBB_Profile_Fields::get_values( $member->user_id, OPENBB_Profile_Fields::CONTEXT_STAFF ),
			);
		}

		return $items;
	}

	private function member_orders( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.cycle_id, o.status, o.total, o.submitted_at, c.name AS cycle_name
				FROM {$wpdb->openbb_orders} o
				LEFT JOIN {$wpdb->openbb_cycles} c ON c.id = o.cycle_id
				WHERE o.user_id = %d
				ORDER BY o.id DESC
				LIMIT 50",
				$user_id
			),
			ARRAY_A
		);

		$labels = OPENBB_Order::status_labels();

		return array_map(
			static function ( array $row ) use ( $labels ): array {
				return array(
					'id'           => (int) $row['id'],
					'cycle_id'     => (int) $row['cycle_id'],
					'cycle_name'   => (string) $row['cycle_name'],
					'status'       => $row['status'],
					'status_label' => $labels[ $row['status'] ] ?? $row['status'],
					'total'        => (int) $row['total'],
					'submitted_at' => self::to_local_datetime( $row['submitted_at'] ),
				);
			},
			(array) $rows
		);
	}
}
