<?php
/**
 * Member profile fields defined by the co-op.
 *
 * The definitions live in one site option. Values are per-site user meta under
 * "{prefix}openbb_field_{key}", a namespace the built-in member fields never use, so a
 * custom field can't overwrite co-op access data. Options fields store one meta row per
 * selected option so they can be filtered with meta_query.
 *
 * What a field definition is and how a value is checked against it comes from
 * OPENBB_Field_Schema, shared with poll questions. This class adds what is specific to
 * profiles: who sees a field, who may change it, and the user meta storage.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Profile_Fields {

	const OPTION         = 'openbb_profile_fields';
	const FORMAT_VERSION = 1;

	const TYPE_TEXT    = OPENBB_Field_Schema::TYPE_TEXT;
	const TYPE_BOOLEAN = OPENBB_Field_Schema::TYPE_BOOLEAN;
	const TYPE_OPTIONS = OPENBB_Field_Schema::TYPE_OPTIONS;
	const TYPES        = OPENBB_Field_Schema::TYPES;

	const STYLE_SELECT = OPENBB_Field_Schema::STYLE_SELECT;
	const STYLE_CHIPS  = OPENBB_Field_Schema::STYLE_CHIPS;
	const STYLES       = array( self::STYLE_SELECT, self::STYLE_CHIPS );

	const VISIBILITY_MEMBER = 'member';
	const VISIBILITY_STAFF  = 'staff';

	/** New account from the signup form: member fields; required enforced. */
	const CONTEXT_SIGNUP = 'signup';
	/** The member editing their own profile: member-editable member fields; required enforced. */
	const CONTEXT_SELF = 'self';
	/** Staff adding a member (new or existing user): all fields; required enforced. */
	const CONTEXT_STAFF_CREATE = 'staff_create';
	/** Staff editing a member: all fields; required not enforced. */
	const CONTEXT_STAFF = 'staff';
	const CONTEXTS      = array( self::CONTEXT_SIGNUP, self::CONTEXT_SELF, self::CONTEXT_STAFF_CREATE, self::CONTEXT_STAFF );

	const KEY_PATTERN        = OPENBB_Field_Schema::KEY_PATTERN;
	const DEFAULT_MAX_LENGTH = OPENBB_Field_Schema::DEFAULT_MAX_LENGTH;
	const MAX_MAX_LENGTH     = OPENBB_Field_Schema::MAX_MAX_LENGTH;

	/**
	 * Keys that would be confused with built-in member or account fields.
	 */
	public static function reserved_keys(): array {
		return array_values(
			array_unique(
				array_merge(
					array_keys( openbb_member_meta_fields() ),
					array( 'email', 'first_name', 'last_name', 'name', 'password', 'user_login', 'id', 'role', 'profile' )
				)
			)
		);
	}

	public static function meta_key( string $key ): string {
		return openbb_member_meta_key( 'field_' . $key );
	}

	/**
	 * The stored schema: version, revision, fields (including archived) and retired keys
	 * (fields whose data was purged; their keys can't be reused).
	 */
	public static function get_schema(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$fields = array();
		foreach ( (array) ( $stored['fields'] ?? array() ) as $field ) {
			$normalized = is_array( $field ) ? self::normalize_field( $field ) : null;
			if ( $normalized && ! is_wp_error( self::validate_field( $normalized ) ) ) {
				$fields[] = $normalized;
			}
		}

		return array(
			'version'      => self::FORMAT_VERSION,
			'revision'     => absint( $stored['revision'] ?? 0 ),
			'fields'       => $fields,
			'retired_keys' => array_values( array_filter( array_map( 'strval', (array) ( $stored['retired_keys'] ?? array() ) ), array( __CLASS__, 'is_valid_key' ) ) ),
		);
	}

	/**
	 * Every defined field (including archived) keyed by field key.
	 */
	public static function get_fields(): array {
		return array_column( self::get_schema()['fields'], null, 'key' );
	}

	/**
	 * The fields a context can read, keyed by field key, after the 'openbb_profile_fields'
	 * filter. Staff contexts include archived fields, which are read-only.
	 */
	public static function get_fields_for_context( string $context ): array {
		$fields = array_filter(
			self::get_fields(),
			static function ( array $field ) use ( $context ): bool {
				return self::is_readable_in( $field, $context );
			}
		);

		/**
		 * Filters the profile fields shown in a context, e.g. to add, remove or reorder them.
		 * Added fields must be valid definitions; invalid ones are dropped.
		 *
		 * @param array  $fields  Field key => definition.
		 * @param string $context One of OPENBB_Profile_Fields::CONTEXTS.
		 */
		$filtered = apply_filters( 'openbb_profile_fields', $fields, $context );

		$valid = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $field ) {
			$field = is_array( $field ) ? self::normalize_field( $field ) : null;
			if ( $field && ! isset( $valid[ $field['key'] ] ) && true === self::validate_field( $field ) && self::is_readable_in( $field, $context ) ) {
				$valid[ $field['key'] ] = $field;
			}
		}

		return $valid;
	}

	private static function is_staff_context( string $context ): bool {
		return self::CONTEXT_STAFF === $context || self::CONTEXT_STAFF_CREATE === $context;
	}

	private static function is_readable_in( array $field, string $context ): bool {
		if ( self::is_staff_context( $context ) ) {
			return true;
		}

		return ! $field['archived'] && self::VISIBILITY_MEMBER === $field['visibility'];
	}

	private static function is_writable_in( array $field, string $context ): bool {
		if ( $field['archived'] || ! self::is_readable_in( $field, $context ) ) {
			return false;
		}

		return self::CONTEXT_SELF !== $context || $field['member_editable'];
	}

	private static function enforces_required( string $context ): bool {
		return self::CONTEXT_STAFF !== $context;
	}

	/**
	 * The rules profile fields give the shared field engine.
	 */
	private static function schema_config(): array {
		return array(
			'error_code'    => 'openbb_invalid_profile_fields',
			'reserved_keys' => self::reserved_keys(),
			'styles'        => self::STYLES,
		);
	}

	public static function is_valid_key( string $key ): bool {
		return OPENBB_Field_Schema::is_valid_key( $key );
	}

	/**
	 * Suggested key for a label: accents removed, lowercase, other characters as "_".
	 * Returns '' when nothing usable is left (callers pick a fallback).
	 */
	public static function key_from_label( string $label ): string {
		return OPENBB_Field_Schema::key_from_label( $label );
	}

	/**
	 * Coerces a submitted definition into the stored shape. Doesn't validate.
	 */
	public static function normalize_field( array $input ): array {
		$base = OPENBB_Field_Schema::normalize_field( $input, self::schema_config() );

		$field = array(
			'key'             => $base['key'],
			'label'           => $base['label'],
			'admin_label'     => openbb_sanitize_localized_text( $input['admin_label'] ?? '' ),
			'help'            => $base['help'],
			'type'            => $base['type'],
			'required'        => $base['required'],
			'visibility'      => self::VISIBILITY_STAFF === ( $input['visibility'] ?? '' ) ? self::VISIBILITY_STAFF : self::VISIBILITY_MEMBER,
			'member_editable' => ! array_key_exists( 'member_editable', $input ) || ! empty( $input['member_editable'] ),
			'archived'        => $base['archived'],
		);

		return $field + array_intersect_key( $base, array_flip( array( 'text', 'options' ) ) );
	}

	/**
	 * Checks one normalized definition on its own (not against other fields or the saved schema).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_field( array $field ) {
		return OPENBB_Field_Schema::validate_field( $field, self::schema_config() );
	}

	/**
	 * Option keys of an options field, in display order.
	 *
	 * @param bool $active_only Leave out archived options.
	 * @return string[]
	 */
	public static function option_keys( array $field, bool $active_only = false ): array {
		return OPENBB_Field_Schema::option_keys( $field, $active_only );
	}

	/**
	 * Validates a complete new field list against the saved schema: keys and types can't
	 * change, saved fields and options can't be removed (archive them instead), and keys
	 * stay unique, including retired ones.
	 *
	 * @param array[] $fields Normalized fields.
	 * @return true|WP_Error
	 */
	public static function validate_schema( array $fields, array $old_schema ) {
		return OPENBB_Field_Schema::validate_fields( $fields, $old_schema['fields'], $old_schema['retired_keys'], self::schema_config() );
	}

	/**
	 * Replaces the field list. $base_revision is the revision the editor loaded; if someone
	 * saved since, nothing is written and a 409 error is returned.
	 *
	 * @return array|WP_Error The saved schema.
	 */
	public static function save_schema( array $fields, int $base_revision ) {
		$old = self::get_schema();
		if ( $base_revision !== $old['revision'] ) {
			return new WP_Error(
				'openbb_profile_fields_stale',
				__( 'Someone else changed the profile fields since you opened them. Reload to see their changes, then make yours again.', 'open-bulk-buying' ),
				array( 'status' => 409 )
			);
		}

		$normalized = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				return self::error( __( 'Each profile field must be an object.', 'open-bulk-buying' ) );
			}
			$normalized[] = self::normalize_field( $field );
		}

		$valid = self::validate_schema( $normalized, $old );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::write_schema( $normalized, $old );
	}

	/**
	 * Deletes an archived field's values for every member on this site, then its definition.
	 * The key stays reserved.
	 *
	 * @return array|WP_Error The saved schema.
	 */
	public static function purge_field( string $key ) {
		$old   = self::get_schema();
		$field = self::get_fields()[ $key ] ?? null;
		if ( ! $field ) {
			return new WP_Error( 'openbb_profile_field_not_found', __( 'That profile field doesn\'t exist.', 'open-bulk-buying' ), array( 'status' => 404 ) );
		}
		if ( ! $field['archived'] ) {
			return new WP_Error( 'openbb_profile_field_not_archived', __( 'Archive the field before deleting it.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		delete_metadata( 'user', 0, self::meta_key( $key ), '', true );

		$fields = array_values(
			array_filter(
				$old['fields'],
				static function ( array $item ) use ( $key ): bool {
					return $item['key'] !== $key;
				}
			)
		);

		$old['retired_keys'][] = $key;

		return self::write_schema( $fields, $old );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function write_schema( array $fields, array $old ) {
		$schema = array(
			'version'      => self::FORMAT_VERSION,
			'revision'     => $old['revision'] + 1,
			'fields'       => array_values( $fields ),
			'retired_keys' => array_values( array_unique( $old['retired_keys'] ) ),
		);

		// Autoloaded: it's small and read on most member requests.
		if ( ! update_option( self::OPTION, $schema, true ) ) {
			return new WP_Error( 'openbb_profile_fields_save_failed', __( 'The profile fields could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		/**
		 * @param array $schema   The saved schema.
		 * @param array $old      The previous schema.
		 * @param int   $actor_id User who saved it.
		 */
		do_action( 'openbb_profile_fields_saved', $schema, $old, get_current_user_id() );

		return $schema;
	}

	/**
	 * A member's values for the fields readable in $context, keyed by field key: a string
	 * for text, a bool for true/false and an array of option keys for options (single-choice
	 * fields return at most one). Option keys no longer in the definition are kept.
	 */
	public static function get_values( int $user_id, string $context ): array {
		$values = array();
		foreach ( self::get_fields_for_context( $context ) as $key => $field ) {
			$values[ $key ] = self::read_value( $user_id, $field );
		}

		return $values;
	}

	private static function read_value( int $user_id, array $field ) {
		$meta_key = self::meta_key( $field['key'] );

		switch ( $field['type'] ) {
			case self::TYPE_BOOLEAN:
				return '1' === get_user_meta( $user_id, $meta_key, true );

			case self::TYPE_OPTIONS:
				$keys = array_values( array_unique( array_map( 'strval', (array) get_user_meta( $user_id, $meta_key, false ) ) ) );
				return $field['options']['multiple'] ? $keys : array_slice( $keys, 0, 1 );

			default:
				return (string) get_user_meta( $user_id, $meta_key, true );
		}
	}

	/**
	 * Validates $values (field key => submitted value) for $context and returns them
	 * sanitized, or a WP_Error whose data has 'errors' => field key => message for inline
	 * form messages. Only the submitted keys are checked, except that in contexts that
	 * enforce `required`, required fields must have a value after the update.
	 *
	 * @return array|WP_Error
	 */
	public static function validate_values( int $user_id, array $values, string $context ) {
		$fields = self::get_fields_for_context( $context );
		$clean  = array();
		$errors = array();

		foreach ( $values as $key => $value ) {
			$key   = (string) $key;
			$field = $fields[ $key ] ?? null;
			if ( ! $field ) {
				$errors[ $key ] = __( 'This field doesn\'t exist.', 'open-bulk-buying' );
				continue;
			}
			if ( ! self::is_writable_in( $field, $context ) ) {
				$errors[ $key ] = __( 'This field can\'t be changed here.', 'open-bulk-buying' );
				continue;
			}

			$result = self::sanitize_value( $field, $value, $user_id );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}

			/**
			 * Filters whether a sanitized profile field value is valid. Return a WP_Error or
			 * false to reject it.
			 *
			 * @param true|WP_Error|false $valid
			 * @param array               $field
			 * @param mixed               $value   Sanitized value.
			 * @param string              $context
			 */
			$valid = apply_filters( 'openbb_profile_field_value_valid', true, $field, $result, $context );
			if ( true !== $valid ) {
				$errors[ $key ] = is_wp_error( $valid ) ? $valid->get_error_message() : __( 'This value isn\'t accepted.', 'open-bulk-buying' );
				continue;
			}

			$clean[ $key ] = $result;
		}

		if ( self::enforces_required( $context ) ) {
			foreach ( $fields as $key => $field ) {
				if ( ! $field['required'] || ! self::is_writable_in( $field, $context ) || isset( $errors[ $key ] ) ) {
					continue;
				}
				$value = array_key_exists( $key, $clean ) ? $clean[ $key ] : ( $user_id ? self::read_value( $user_id, $field ) : null );
				if ( self::is_empty_value( $value ) ) {
					$errors[ $key ] = self::TYPE_BOOLEAN === $field['type']
						? __( 'This box must be checked.', 'open-bulk-buying' )
						: __( 'This field is required.', 'open-bulk-buying' );
				}
			}
		}

		if ( $errors ) {
			return new WP_Error(
				'openbb_profile_fields_invalid',
				__( 'Some profile fields need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return $clean;
	}

	private static function is_empty_value( $value ): bool {
		return OPENBB_Field_Schema::is_empty_value( $value );
	}

	/**
	 * Archived options stay valid for members who already chose them.
	 *
	 * @return mixed|WP_Error
	 */
	private static function sanitize_value( array $field, $value, int $user_id ) {
		$kept = self::TYPE_OPTIONS === $field['type'] && $user_id ? self::read_value( $user_id, $field ) : array();

		return OPENBB_Field_Schema::sanitize_value( $field, $value, $kept );
	}

	/**
	 * Validates and saves profile values in one transaction. Nothing is written if any
	 * value is invalid.
	 *
	 * Not for use inside another transaction: MySQL commits the outer one on START TRANSACTION.
	 *
	 * @return true|WP_Error
	 */
	public static function set_values( int $user_id, array $values, string $context ) {
		global $wpdb;

		if ( ! in_array( $context, self::CONTEXTS, true ) || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_profile_context', __( 'The profile could not be saved.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$clean = self::validate_values( $user_id, $values, $context );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( ! $clean ) {
			return true;
		}

		$fields = self::get_fields();
		$failed = new WP_Error( 'openbb_profile_save_failed', __( 'The profile could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $failed;
		}

		$old = array();
		foreach ( $clean as $key => $value ) {
			$old[ $key ] = self::read_value( $user_id, $fields[ $key ] );
			if ( ! self::write_value( $user_id, $fields[ $key ], $value ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_delete( $user_id, 'user_meta' );
				return $failed;
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_delete( $user_id, 'user_meta' );
			return $failed;
		}

		/**
		 * @param int    $user_id
		 * @param array  $values   Field key => new value (only the changed request keys).
		 * @param array  $old      Field key => previous value.
		 * @param string $context
		 * @param int    $actor_id
		 */
		do_action( 'openbb_profile_values_changed', $user_id, $clean, $old, $context, get_current_user_id() );

		return true;
	}

	private static function write_value( int $user_id, array $field, $value ): bool {
		$meta_key = self::meta_key( $field['key'] );
		$exists   = metadata_exists( 'user', $user_id, $meta_key );

		if ( self::is_empty_value( $value ) ) {
			return ! $exists || delete_user_meta( $user_id, $meta_key );
		}

		if ( self::TYPE_OPTIONS === $field['type'] ) {
			if ( $exists && ! delete_user_meta( $user_id, $meta_key ) ) {
				return false;
			}
			foreach ( $value as $option_key ) {
				if ( ! add_user_meta( $user_id, $meta_key, $option_key ) ) {
					return false;
				}
			}
			return true;
		}

		$stored = self::TYPE_BOOLEAN === $field['type'] ? '1' : (string) $value;

		return ( $exists && get_user_meta( $user_id, $meta_key, true ) === $stored )
			|| false !== update_user_meta( $user_id, $meta_key, $stored );
	}

	/**
	 * A meta_query clause matching members whose $key field has $value: a string for text
	 * (exact match), true/false for true/false fields, or an option key.
	 */
	public static function meta_query_clause( array $field, $value ): array {
		$meta_key = self::meta_key( $field['key'] );

		if ( self::TYPE_BOOLEAN === $field['type'] ) {
			return rest_sanitize_boolean( $value )
				? array(
					'key'   => $meta_key,
					'value' => '1',
				)
				: array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				);
		}

		return array(
			'key'   => $meta_key,
			'value' => (string) $value,
		);
	}

	/**
	 * Registers each field's meta so core's meta APIs sanitize it. Writes go through
	 * set_values(); the core meta API may only edit member-editable member fields.
	 *
	 * Hooked to 'init'.
	 */
	public static function register_meta(): void {
		foreach ( self::get_fields() as $key => $field ) {
			$editable = self::VISIBILITY_MEMBER === $field['visibility'] && $field['member_editable'] && ! $field['archived'];
			register_meta(
				'user',
				self::meta_key( $key ),
				array(
					'type'              => 'string',
					'single'            => self::TYPE_OPTIONS !== $field['type'],
					'show_in_rest'      => false,
					'sanitize_callback' => self::TYPE_TEXT === $field['type'] && $field['text']['multiline'] ? 'sanitize_textarea_field' : 'sanitize_text_field',
					'auth_callback'     => $editable ? 'openbb_can_edit_member_meta' : '__return_false',
				)
			);
		}
	}

	private static function error( string $message, string $field_key = '' ): WP_Error {
		return OPENBB_Field_Schema::error( $message, $field_key, self::schema_config() );
	}
}
