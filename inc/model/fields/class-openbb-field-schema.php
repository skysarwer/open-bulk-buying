<?php
/**
 * Field definitions shared by the features that let a co-op write its own questions:
 * member profile fields (OPENBB_Profile_Fields) and poll questions (OPENBB_Polls).
 *
 * This class only knows the definition of a field and how to check a submitted value
 * against it. Where definitions and values are stored, who may read or write them and
 * what happens to them over time belong to each consumer.
 *
 * A consumer passes its rules as $config (see config()), so the two can differ without
 * forking the engine.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Field_Schema {

	const TYPE_TEXT    = 'text';
	const TYPE_BOOLEAN = 'boolean';
	const TYPE_OPTIONS = 'options';
	const TYPES        = array( self::TYPE_TEXT, self::TYPE_BOOLEAN, self::TYPE_OPTIONS );

	const STYLE_SELECT = 'select';
	const STYLE_CHIPS  = 'chips';
	/** A rating: one choice among the fixed options "1" to STAR_COUNT. */
	const STYLE_STARS = 'stars';
	const STAR_COUNT  = 5;

	const KEY_PATTERN        = '/^[a-z][a-z0-9_]{0,39}$/';
	const DEFAULT_MAX_LENGTH = 200;
	const MAX_MAX_LENGTH     = 5000;

	/**
	 * A consumer's rules, with defaults:
	 *
	 * - error_code    Code of the WP_Errors returned for invalid definitions.
	 * - reserved_keys Field keys that can't be used.
	 * - styles        Styles an options field may use; the first is the default.
	 * - required      Whether fields can be required. When false, `required` is always false.
	 */
	public static function config( array $config = array() ): array {
		return array_merge(
			array(
				'error_code'    => 'openbb_invalid_fields',
				'reserved_keys' => array(),
				'styles'        => array( self::STYLE_SELECT, self::STYLE_CHIPS ),
				'required'      => true,
			),
			$config
		);
	}

	public static function is_valid_key( string $key ): bool {
		return (bool) preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Suggested key for a label: accents removed, lowercase, other characters as "_".
	 * Returns '' when nothing usable is left (callers pick a fallback).
	 */
	public static function key_from_label( string $label ): string {
		$key = strtolower( remove_accents( $label ) );
		$key = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $key ), '_' );
		$key = ltrim( $key, '0123456789_' );

		return substr( $key, 0, 40 );
	}

	public static function is_stars( array $field ): bool {
		return self::TYPE_OPTIONS === ( $field['type'] ?? '' ) && self::STYLE_STARS === ( $field['options']['style'] ?? '' );
	}

	/**
	 * Option keys of a star rating, lowest first.
	 *
	 * @return string[]
	 */
	public static function star_keys(): array {
		return array_map( 'strval', range( 1, self::STAR_COUNT ) );
	}

	/**
	 * Coerces a submitted definition into the stored shape: key, label, help, type,
	 * required, archived, plus `text` or `options` for those types. Doesn't validate.
	 */
	public static function normalize_field( array $input, array $config = array() ): array {
		$config = self::config( $config );
		$type   = in_array( $input['type'] ?? '', self::TYPES, true ) ? $input['type'] : '';

		$field = array(
			'key'      => is_string( $input['key'] ?? null ) ? $input['key'] : '',
			'label'    => openbb_sanitize_localized_text( $input['label'] ?? '' ),
			'help'     => openbb_sanitize_localized_text( $input['help'] ?? '' ),
			'type'     => $type,
			'required' => $config['required'] && ! empty( $input['required'] ),
			'archived' => ! empty( $input['archived'] ),
		);

		if ( self::TYPE_TEXT === $type ) {
			$text          = is_array( $input['text'] ?? null ) ? $input['text'] : array();
			$field['text'] = array(
				'multiline'  => ! empty( $text['multiline'] ),
				'max_length' => isset( $text['max_length'] ) && is_numeric( $text['max_length'] ) ? (int) $text['max_length'] : self::DEFAULT_MAX_LENGTH,
			);
		}

		if ( self::TYPE_OPTIONS === $type ) {
			$options = is_array( $input['options'] ?? null ) ? $input['options'] : array();
			$style   = in_array( $options['style'] ?? '', $config['styles'], true ) ? $options['style'] : $config['styles'][0];

			$field['options'] = self::STYLE_STARS === $style
				? self::star_options()
				: array(
					'style'    => $style,
					'multiple' => ! empty( $options['multiple'] ),
					'groups'   => self::normalize_groups( (array) ( $options['groups'] ?? array() ) ),
				);
		}

		return $field;
	}

	private static function normalize_groups( array $input ): array {
		$groups = array();
		foreach ( $input as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group_key = is_string( $group['key'] ?? null ) ? $group['key'] : '';
			$choices   = array();
			foreach ( (array) ( $group['options'] ?? array() ) as $option ) {
				if ( is_array( $option ) ) {
					$choices[] = array(
						'key'      => is_string( $option['key'] ?? null ) ? $option['key'] : '',
						'label'    => openbb_sanitize_localized_text( $option['label'] ?? '' ),
						'archived' => ! empty( $option['archived'] ),
					);
				}
			}
			$groups[] = array(
				'key'     => $group_key,
				'label'   => '' === $group_key ? null : openbb_sanitize_localized_text( $group['label'] ?? '' ),
				'options' => $choices,
			);
		}

		return $groups;
	}

	/**
	 * The options of a star rating are fixed, so they are generated, never read from input.
	 */
	private static function star_options(): array {
		$choices = array();
		foreach ( self::star_keys() as $key ) {
			$choices[] = array(
				'key'      => $key,
				'label'    => array( 'default' => $key ),
				'archived' => false,
			);
		}

		return array(
			'style'    => self::STYLE_STARS,
			'multiple' => false,
			'groups'   => array(
				array(
					'key'     => '',
					'label'   => null,
					'options' => $choices,
				),
			),
		);
	}

	/**
	 * Checks one normalized definition on its own (not against other fields or a saved schema).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_field( array $field, array $config = array() ) {
		$config = self::config( $config );
		$name   = '' !== $field['label']['default'] ? $field['label']['default'] : $field['key'];

		if ( ! self::is_valid_key( $field['key'] ) ) {
			return self::error(
				/* translators: %s: field key. */
				sprintf( __( '"%s" is not a valid field key. Use lowercase letters, numbers and underscores, starting with a letter (at most 40 characters).', 'open-bulk-buying' ), $field['key'] ),
				$field['key'],
				$config
			);
		}
		if ( in_array( $field['key'], $config['reserved_keys'], true ) ) {
			return self::error(
				/* translators: %s: field key. */
				sprintf( __( 'The key "%s" is reserved. Choose another key.', 'open-bulk-buying' ), $field['key'] ),
				$field['key'],
				$config
			);
		}
		if ( '' === trim( $field['label']['default'] ) ) {
			return self::error(
				/* translators: %s: field key. */
				sprintf( __( 'The field "%s" needs a label.', 'open-bulk-buying' ), $field['key'] ),
				$field['key'],
				$config
			);
		}
		if ( '' === $field['type'] ) {
			return self::error(
				/* translators: %s: field label. */
				sprintf( __( 'Choose a type for "%s".', 'open-bulk-buying' ), $name ),
				$field['key'],
				$config
			);
		}

		if ( self::TYPE_TEXT === $field['type'] && ( $field['text']['max_length'] < 1 || $field['text']['max_length'] > self::MAX_MAX_LENGTH ) ) {
			return self::error(
				/* translators: 1: field label, 2: largest allowed maximum length. */
				sprintf( __( 'The maximum length of "%1$s" must be between 1 and %2$d.', 'open-bulk-buying' ), $name, self::MAX_MAX_LENGTH ),
				$field['key'],
				$config
			);
		}

		if ( self::TYPE_OPTIONS === $field['type'] ) {
			return self::validate_options( $field, $name, $config );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function validate_options( array $field, string $name, array $config ) {
		$options = $field['options'];

		if ( self::STYLE_STARS === $options['style'] ) {
			// normalize_field() generates these; anything else was built by hand.
			return in_array( self::STYLE_STARS, $config['styles'], true ) && ! $options['multiple'] && self::option_keys( $field ) === self::star_keys()
				? true
				: self::error(
					/* translators: %s: field label. */
					sprintf( __( '"%s" is not a valid star rating.', 'open-bulk-buying' ), $name ),
					$field['key'],
					$config
				);
		}

		if ( self::STYLE_SELECT === $options['style'] && $options['multiple'] ) {
			return self::error(
				/* translators: %s: field label. */
				sprintf( __( '"%s" allows several choices, so it must use the chips style.', 'open-bulk-buying' ), $name ),
				$field['key'],
				$config
			);
		}

		$group_keys  = array();
		$option_keys = array();
		foreach ( $options['groups'] as $index => $group ) {
			if ( '' === $group['key'] ) {
				if ( 0 !== $index ) {
					return self::error(
						/* translators: %s: field label. */
						sprintf( __( 'Every option group of "%s" after the first needs a key.', 'open-bulk-buying' ), $name ),
						$field['key'],
						$config
					);
				}
			} else {
				if ( ! self::is_valid_key( $group['key'] ) || isset( $group_keys[ $group['key'] ] ) ) {
					return self::error(
						/* translators: 1: group key, 2: field label. */
						sprintf( __( 'The group key "%1$s" in "%2$s" is invalid or used twice.', 'open-bulk-buying' ), $group['key'], $name ),
						$field['key'],
						$config
					);
				}
				if ( '' === trim( $group['label']['default'] ) ) {
					return self::error(
						/* translators: %s: field label. */
						sprintf( __( 'Every option group of "%s" needs a label.', 'open-bulk-buying' ), $name ),
						$field['key'],
						$config
					);
				}
				$group_keys[ $group['key'] ] = true;
			}

			foreach ( $group['options'] as $option ) {
				if ( ! self::is_valid_key( $option['key'] ) || isset( $option_keys[ $option['key'] ] ) ) {
					return self::error(
						/* translators: 1: option key, 2: field label. */
						sprintf( __( 'The option key "%1$s" in "%2$s" is invalid or used twice.', 'open-bulk-buying' ), $option['key'], $name ),
						$field['key'],
						$config
					);
				}
				if ( '' === trim( $option['label']['default'] ) ) {
					return self::error(
						/* translators: %s: field label. */
						sprintf( __( 'Every option of "%s" needs a label.', 'open-bulk-buying' ), $name ),
						$field['key'],
						$config
					);
				}
				$option_keys[ $option['key'] ] = true;
			}
		}

		if ( ! $option_keys ) {
			return self::error(
				/* translators: %s: field label. */
				sprintf( __( '"%s" needs at least one option.', 'open-bulk-buying' ), $name ),
				$field['key'],
				$config
			);
		}

		return true;
	}

	/**
	 * Option keys of an options field, in display order.
	 *
	 * @param bool $active_only Leave out archived options.
	 * @return string[]
	 */
	public static function option_keys( array $field, bool $active_only = false ): array {
		$keys = array();
		foreach ( $field['options']['groups'] ?? array() as $group ) {
			foreach ( $group['options'] as $option ) {
				if ( ! $active_only || ! $option['archived'] ) {
					$keys[] = (string) $option['key'];
				}
			}
		}

		return $keys;
	}

	/**
	 * Option key => label in $language (null: the current one), including archived options.
	 *
	 * @return array<string, string>
	 */
	public static function option_labels( array $field, ?string $language = null ): array {
		$labels = array();
		foreach ( $field['options']['groups'] ?? array() as $group ) {
			foreach ( $group['options'] as $option ) {
				$labels[ (string) $option['key'] ] = openbb_localized( $option['label'], $language );
			}
		}

		return $labels;
	}

	/**
	 * Validates a complete new field list against the saved one: every field is valid,
	 * keys are unique and not retired, and what is saved keeps its meaning. Keys and types
	 * can't change, and saved fields and options can't be removed (archive them instead).
	 *
	 * @param array[]  $fields       Normalized fields.
	 * @param array[]  $old_fields   The saved fields. Pass none while nothing depends on them yet.
	 * @param string[] $retired_keys Keys of deleted fields, which can't be reused.
	 * @return true|WP_Error
	 */
	public static function validate_fields( array $fields, array $old_fields, array $retired_keys = array(), array $config = array() ) {
		$config     = self::config( $config );
		$old_fields = array_column( $old_fields, null, 'key' );
		$seen       = array();

		foreach ( $fields as $field ) {
			$valid = self::validate_field( $field, $config );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$key = $field['key'];
			if ( isset( $seen[ $key ] ) ) {
				return self::error(
					/* translators: %s: field key. */
					sprintf( __( 'The key "%s" is used by more than one field.', 'open-bulk-buying' ), $key ),
					$key,
					$config
				);
			}
			$seen[ $key ] = true;

			if ( in_array( $key, $retired_keys, true ) ) {
				return self::error(
					/* translators: %s: field key. */
					sprintf( __( 'The key "%s" belonged to a deleted field and can\'t be reused.', 'open-bulk-buying' ), $key ),
					$key,
					$config
				);
			}

			$old = $old_fields[ $key ] ?? null;
			if ( ! $old ) {
				continue;
			}
			if ( $old['type'] !== $field['type'] || self::is_stars( $old ) !== self::is_stars( $field ) ) {
				return self::error(
					/* translators: %s: field label. */
					sprintf( __( 'The type of "%s" can\'t change once it is saved. Archive it and add a new field instead.', 'open-bulk-buying' ), $field['label']['default'] ),
					$key,
					$config
				);
			}
			if ( self::TYPE_OPTIONS === $field['type'] ) {
				$missing = array_diff( self::option_keys( $old ), self::option_keys( $field ) );
				if ( $missing ) {
					return self::error(
						/* translators: 1: option key, 2: field label. */
						sprintf( __( 'The saved option "%1$s" of "%2$s" can\'t be removed. Archive it instead.', 'open-bulk-buying' ), reset( $missing ), $field['label']['default'] ),
						$key,
						$config
					);
				}
			}
		}

		$removed = array_diff( array_keys( $old_fields ), array_keys( $seen ) );
		if ( $removed ) {
			return self::error(
				/* translators: %s: field key. */
				sprintf( __( 'The saved field "%s" can\'t be removed. Archive it instead.', 'open-bulk-buying' ), reset( $removed ) ),
				(string) reset( $removed ),
				$config
			);
		}

		return true;
	}

	/**
	 * Whether a value means "nothing entered".
	 */
	public static function is_empty_value( $value ): bool {
		return null === $value || '' === $value || false === $value || array() === $value;
	}

	/**
	 * Checks a submitted value against a field and returns it in its stored form: a string
	 * for text, a bool for true/false and a list of option keys for options.
	 *
	 * @param string[] $kept Option keys the person already holds; these stay valid after
	 *                       the option is archived.
	 * @return mixed|WP_Error
	 */
	public static function sanitize_value( array $field, $value, array $kept = array() ) {
		switch ( $field['type'] ) {
			case self::TYPE_BOOLEAN:
				if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1', 'true', 'false', '' ), true ) ) {
					return new WP_Error( 'openbb_invalid', __( 'Choose yes or no.', 'open-bulk-buying' ) );
				}
				return rest_sanitize_boolean( $value );

			case self::TYPE_OPTIONS:
				if ( null === $value || '' === $value ) {
					$value = array();
				}
				if ( is_string( $value ) ) {
					$value = array( $value );
				}
				if ( ! is_array( $value ) || count( array_filter( $value, 'is_string' ) ) !== count( $value ) ) {
					return new WP_Error( 'openbb_invalid', __( 'Choose from the listed options.', 'open-bulk-buying' ) );
				}
				$value = array_values( array_unique( $value ) );

				if ( array_diff( $value, array_merge( self::option_keys( $field, true ), $kept ) ) ) {
					return new WP_Error( 'openbb_invalid', __( 'Choose from the listed options.', 'open-bulk-buying' ) );
				}
				if ( ! $field['options']['multiple'] && count( $value ) > 1 ) {
					return new WP_Error( 'openbb_invalid', __( 'Choose only one option.', 'open-bulk-buying' ) );
				}
				return $value;

			default:
				if ( ! is_scalar( $value ) && null !== $value ) {
					return new WP_Error( 'openbb_invalid', __( 'Enter text.', 'open-bulk-buying' ) );
				}
				$value = $field['text']['multiline'] ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
				$value = trim( $value );
				if ( mb_strlen( $value ) > $field['text']['max_length'] ) {
					return new WP_Error(
						'openbb_invalid',
						/* translators: %d: maximum number of characters. */
						sprintf( __( 'Use at most %d characters.', 'open-bulk-buying' ), $field['text']['max_length'] )
					);
				}
				return $value;
		}
	}

	public static function error( string $message, string $field_key = '', array $config = array() ): WP_Error {
		return new WP_Error(
			self::config( $config )['error_code'],
			$message,
			array(
				'status' => 400,
				'field'  => $field_key,
			)
		);
	}
}
