<?php
/**
 * Shared helpers for the openbb/v1 REST controllers. Controllers for the admin
 * screens live in admin/, those for member-facing (Interactivity API) views in frontend/.
 *
 * Every admin route checks a co-op capability in its permission_callback and handlers
 * re-check object capabilities. Frontend routes open to visitors (signup) check that
 * the feature is open instead, and rate-limit. Cookie-authenticated requests need the
 * wp_rest nonce (sent by @wordpress/api-fetch and the frontend stores), so writes are CSRF-protected.
 * Responses are built field by field; entity to_array() is never sent as-is.
 *
 * Datetimes are UTC in storage and ISO 8601 with the site's offset over the API.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OPENBB_REST_Controller {

	const API_NAMESPACE = 'openbb/v1';

	abstract public function register_routes(): void;

	/**
	 * Registers every controller on rest_api_init.
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				$controllers = array(
					new OPENBB_REST_Products(),
					new OPENBB_REST_Cycles(),
					new OPENBB_REST_Members(),
					new OPENBB_REST_Orders(),
					new OPENBB_REST_Settings(),
					new OPENBB_REST_Signup(),
					new OPENBB_REST_Login(),
					new OPENBB_REST_Order(),
				);
				foreach ( $controllers as $controller ) {
					$controller->register_routes();
				}
			}
		);
	}

	/**
	 * 401 when logged out, 403 without the capability.
	 *
	 * @return true|WP_Error
	 */
	public static function authorize( string $cap, ...$args ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'open-bulk-buying' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( $cap, ...$args ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to do that.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Passes when the user has any of $caps.
	 *
	 * @param string[] $caps
	 * @return true|WP_Error
	 */
	public static function authorize_any( array $caps ) {
		$result = true;
		foreach ( $caps as $cap ) {
			$result = self::authorize( $cap );
			if ( true === $result ) {
				return true;
			}
		}

		return $result;
	}

	public static function not_found( string $message ): WP_Error {
		return new WP_Error( 'rest_not_found', $message, array( 'status' => 404 ) );
	}

	public static function invalid( string $message, string $code = 'rest_invalid_param' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * Standard list arguments: page, per_page, search, orderby, order.
	 *
	 * @param string[] $orderby Allowed orderby values; the first is the default.
	 */
	protected static function collection_args( array $orderby, string $default_order = 'desc', array $extra = array() ): array {
		return array_merge(
			array(
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
				'search'   => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'orderby'  => array(
					'type'    => 'string',
					'default' => $orderby[0],
					'enum'    => $orderby,
				),
				'order'    => array(
					'type'    => 'string',
					'default' => $default_order,
					'enum'    => array( 'asc', 'desc' ),
				),
			),
			$extra
		);
	}

	protected static function id_arg(): array {
		return array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
	}

	/**
	 * Wraps a list in a response with WordPress's X-WP-Total / X-WP-TotalPages headers.
	 */
	protected static function paginated( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = rest_ensure_response( array_values( $items ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );

		return $response;
	}

	/**
	 * UTC "Y-m-d H:i:s" to ISO 8601 in the site's timezone, with offset; null stays null.
	 */
	public static function to_local_datetime( ?string $gmt ): ?string {
		if ( null === $gmt || '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $gmt, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}

		return $date->setTimezone( wp_timezone() )->format( DATE_ATOM );
	}

	/**
	 * Accepts an ISO 8601 datetime. Without an offset it is site-local time. Returns
	 * UTC "Y-m-d H:i:s", null for empty values, or a WP_Error.
	 *
	 * @return string|null|WP_Error
	 */
	public static function to_gmt_datetime( $value, string $label ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$invalid = self::invalid(
			/* translators: %s: field label. */
			sprintf( __( '%s is not a valid date and time.', 'open-bulk-buying' ), $label )
		);
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $value ) ) {
			return $invalid;
		}

		try {
			$has_offset = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value );
			$date       = new DateTimeImmutable( $value, $has_offset ? null : wp_timezone() );
		} catch ( Exception $e ) {
			return $invalid;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Languages with their own translation fields: the site's languages except the
	 * default one, whose text is the localized value's 'default' entry.
	 *
	 * @return string[]
	 */
	public static function translation_languages(): array {
		$default = apply_filters( 'wpml_default_language', null );
		$default = is_string( $default ) ? sanitize_key( $default ) : '';

		return array_values(
			array_filter(
				openbb_languages(),
				static function ( string $language ) use ( $default ): bool {
					return 'default' !== $language && $language !== $default;
				}
			)
		);
	}

	/**
	 * Flattens a localized value to $field (default text) and "{$field}_{$language}".
	 */
	protected static function localized_out( string $field, array $value ): array {
		$value = openbb_normalize_localized( $value );
		$out   = array( $field => $value['default'] );
		foreach ( self::translation_languages() as $language ) {
			$out[ $field . '_' . $language ] = $value[ $language ] ?? '';
		}

		return $out;
	}

	/**
	 * Merges $field / "{$field}_{$language}" request params into $current.
	 * Empty translations are dropped, so they fall back to the default text.
	 */
	protected static function localized_in( WP_REST_Request $request, string $field, array $current ): array {
		$value = openbb_normalize_localized( $current );
		if ( $request->has_param( $field ) ) {
			$value['default'] = (string) $request->get_param( $field );
		}
		foreach ( self::translation_languages() as $language ) {
			$key = $field . '_' . $language;
			if ( $request->has_param( $key ) ) {
				$text = (string) $request->get_param( $key );
				if ( '' === trim( $text ) ) {
					unset( $value[ $language ] );
				} else {
					$value[ $language ] = $text;
				}
			}
		}

		return $value;
	}

	/**
	 * Schema args for the localized fields of $field.
	 */
	protected static function localized_args( string $field ): array {
		$args = array(
			$field => array( 'type' => 'string' ),
		);
		foreach ( self::translation_languages() as $language ) {
			$args[ $field . '_' . $language ] = array( 'type' => 'string' );
		}

		return $args;
	}

	/**
	 * A small user summary for list columns.
	 */
	protected static function user_summary( int $user_id ): array {
		$user = $user_id ? get_userdata( $user_id ) : false;

		return array(
			'id'    => $user_id,
			'name'  => $user ? $user->display_name : __( '(deleted user)', 'open-bulk-buying' ),
			'email' => $user ? $user->user_email : '',
		);
	}
}
