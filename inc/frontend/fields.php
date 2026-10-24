<?php
/**
 * Reusable form field partials for frontend views, wired to the `openbb/forms` store
 * (assets/js/forms.js). Markup follows: <select> with <optgroup>s,
 * chips as <fieldset>/<legend> groups of native radios or checkboxes.
 *
 * A field lives inside an element with the `openbb/forms` context:
 *
 *     data-wp-context='openbb/forms::{"values": {...}, "errors": {}}'
 *
 * Inputs are uncontrolled: their initial value is rendered here, and `actions.change`
 * copies changes into `context.values`. Directives are prefixed with the namespace, so
 * fields work inside any interactive region.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field kinds openbb_render_field() supports.
 */
function openbb_field_kinds(): array {
	return array( 'text', 'email', 'tel', 'password', 'textarea', 'select', 'chips', 'checkbox' );
}

/**
 * Renders one field.
 *
 * @param array $field {
 *     @type string $name         Value key in context.values and in server errors.
 *     @type string $label        Plain text.
 *     @type string $help         Plain text, optional.
 *     @type string $kind         One of openbb_field_kinds().
 *     @type bool   $required
 *     @type bool   $locked       Shown greyed out and read-only (e.g. a value the user already has).
 *     @type bool   $multiple     select and chips: several values.
 *     @type array  $groups       select and chips: list of [ 'label' => ?string, 'options' => [ [ 'value', 'label' ] ] ].
 *                                A group without label holds ungrouped options and comes first.
 *     @type mixed  $value        string, list of strings (multiple) or bool (checkbox).
 *     @type string $autocomplete
 *     @type string $inputmode    Text inputs: virtual keyboard hint, e.g. decimal.
 *     @type int    $maxlength
 *     @type string $id_prefix    Prefix for element ids, unique per form on the page.
 * }
 */
function openbb_render_field( array $field ): string {
	$field = array_merge(
		array(
			'name'         => '',
			'label'        => '',
			'help'         => '',
			'kind'         => 'text',
			'required'     => false,
			'locked'       => false,
			'multiple'     => false,
			'groups'       => array(),
			'value'        => null,
			'autocomplete' => '',
			'inputmode'    => '',
			'maxlength'    => 0,
			'id_prefix'    => 'openbb',
		),
		$field
	);
	if ( '' === $field['name'] || ! in_array( $field['kind'], openbb_field_kinds(), true ) ) {
		return '';
	}

	$name   = $field['name'];
	$id     = sanitize_html_class( $field['id_prefix'] . '-' . $name );
	$errors = 'openbb/forms::context.errors.' . $name;

	$described = array();
	if ( '' !== $field['help'] ) {
		$described[] = $id . '-help';
	}
	$described[] = $id . '-error';

	$control_attrs = array(
		'aria-describedby' => implode( ' ', $described ),
		'data-wp-watch'    => 'openbb/forms::callbacks.syncInvalid',
	);

	$wrapper = sprintf(
		'<div class="%s" data-openbb-field data-name="%s" data-kind="%s"%s%s data-wp-class--has-error="%s">',
		esc_attr( 'openbb-field openbb-field--' . $field['kind'] . ( $field['locked'] ? ' is-locked' : '' ) ),
		esc_attr( $name ),
		esc_attr( $field['kind'] ),
		$field['required'] ? ' data-required' : '',
		$field['locked'] ? ' data-locked' : '',
		esc_attr( $errors )
	);

	$help = '' !== $field['help'] ? sprintf( '<p class="openbb-field__help" id="%s-help">%s</p>', esc_attr( $id ), esc_html( $field['help'] ) ) : '';

	$error = sprintf(
		'<p class="openbb-field__error" id="%1$s-error" data-wp-bind--hidden="openbb/forms::!context.errors.%2$s" data-wp-text="%3$s"></p>',
		esc_attr( $id ),
		esc_attr( $name ),
		esc_attr( $errors )
	);

	$required_mark = $field['required'] ? ' <span class="openbb-field__required" aria-hidden="true">*</span>' : '';

	switch ( $field['kind'] ) {
		case 'checkbox':
			$input = '<input' . openbb_field_attrs(
				array_merge(
					array(
						'type'               => 'checkbox',
						'class'              => 'openbb-check__input',
						'id'                 => $id,
						'name'               => $name,
						'value'              => '1',
						'checked'            => (bool) $field['value'],
						'required'           => $field['required'],
						'disabled'           => $field['locked'],
						'data-wp-on--change' => 'openbb/forms::actions.change',
					),
					$control_attrs
				)
			) . '>';

			return $wrapper
				. sprintf( '<label class="openbb-check" for="%s">%s<span class="openbb-check__label">%s%s</span></label>', esc_attr( $id ), $input, esc_html( $field['label'] ), $required_mark )
				. $help . $error . '</div>';

		case 'chips':
			return $wrapper
				. sprintf( '<fieldset class="openbb-chips" aria-describedby="%s"><legend class="openbb-field__label">%s%s</legend>', esc_attr( implode( ' ', $described ) ), esc_html( $field['label'] ), $required_mark )
				. $help
				. openbb_render_chip_groups( $field, $id, $control_attrs )
				. '</fieldset>' . $error . '</div>';

		case 'select':
			$attrs = array_merge(
				array(
					'id'                 => $id,
					'name'               => $field['multiple'] ? $name . '[]' : $name,
					'class'              => 'openbb-input openbb-select',
					'multiple'           => $field['multiple'],
					'required'           => $field['required'],
					'disabled'           => $field['locked'],
					'autocomplete'       => $field['autocomplete'],
					'data-wp-on--change' => 'openbb/forms::actions.change',
				),
				$control_attrs
			);

			return $wrapper
				. openbb_field_label( $id, $field['label'], $required_mark )
				. $help
				. '<select' . openbb_field_attrs( $attrs ) . '>' . openbb_render_select_options( $field ) . '</select>'
				. $error . '</div>';

		default:
			$attrs = array_merge(
				array(
					'id'                => $id,
					'name'              => $name,
					'class'             => 'openbb-input',
					'required'          => $field['required'],
					'readonly'          => $field['locked'],
					'autocomplete'      => $field['autocomplete'],
					'inputmode'         => $field['inputmode'],
					'maxlength'         => $field['maxlength'] > 0 ? (string) $field['maxlength'] : '',
					'data-wp-on--input' => 'openbb/forms::actions.change',
				),
				$control_attrs
			);
			$value = is_scalar( $field['value'] ) ? (string) $field['value'] : '';

			$control = 'textarea' === $field['kind']
				? '<textarea rows="4"' . openbb_field_attrs( $attrs ) . '>' . esc_textarea( $value ) . '</textarea>'
				: '<input' . openbb_field_attrs( array( 'type' => $field['kind'] ) + $attrs + array( 'value' => $value ) ) . '>';

			return $wrapper . openbb_field_label( $id, $field['label'], $required_mark ) . $help . $control . $error . '</div>';
	}
}

/**
 * The input for a profile field definition (OPENBB_Profile_Fields), localized.
 * Archived options are only listed when they're part of $value.
 */
function openbb_profile_field_input( array $definition, $value, array $args = array() ): array {
	$field = array_merge(
		array(
			'name'     => $definition['key'],
			'label'    => openbb_localized( $definition['label'] ),
			'help'     => openbb_localized( $definition['help'] ),
			'required' => (bool) $definition['required'],
		),
		$args
	);

	switch ( $definition['type'] ) {
		case OPENBB_Profile_Fields::TYPE_BOOLEAN:
			$field['kind']  = 'checkbox';
			$field['value'] = (bool) $value;
			break;

		case OPENBB_Profile_Fields::TYPE_OPTIONS:
			$multiple = (bool) $definition['options']['multiple'];
			$selected = array_map( 'strval', (array) $value );
			$groups   = array();
			foreach ( $definition['options']['groups'] as $group ) {
				$options = array();
				foreach ( $group['options'] as $option ) {
					if ( ! $option['archived'] || in_array( $option['key'], $selected, true ) ) {
						$options[] = array(
							'value' => $option['key'],
							'label' => openbb_localized( $option['label'] ),
						);
					}
				}
				if ( $options ) {
					$groups[] = array(
						'label'   => '' === $group['key'] ? null : openbb_localized( $group['label'] ),
						'options' => $options,
					);
				}
			}

			$field['kind']     = OPENBB_Profile_Fields::STYLE_CHIPS === $definition['options']['style'] ? 'chips' : 'select';
			$field['multiple'] = $multiple;
			$field['groups']   = $groups;
			$field['value']    = $multiple ? $selected : (string) ( $selected[0] ?? '' );
			break;

		default:
			$field['kind']      = $definition['text']['multiline'] ? 'textarea' : 'text';
			$field['maxlength'] = (int) $definition['text']['max_length'];
			$field['value']     = is_scalar( $value ) ? (string) $value : '';
	}

	return $field;
}

/**
 * The value a field holds in context.values when it starts empty.
 */
function openbb_field_empty_value( array $field ) {
	if ( 'checkbox' === ( $field['kind'] ?? '' ) ) {
		return false;
	}

	return empty( $field['multiple'] ) ? '' : array();
}

/**
 * Builds an attribute string. true renders a bare attribute; false and '' are skipped.
 */
function openbb_field_attrs( array $attrs ): string {
	$html = '';
	foreach ( $attrs as $name => $value ) {
		if ( false === $value || '' === $value || null === $value ) {
			continue;
		}
		$html .= true === $value ? ' ' . esc_attr( $name ) : sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
	}

	return $html;
}

function openbb_field_label( string $id, string $label, string $required_mark ): string {
	return sprintf( '<label class="openbb-field__label" for="%s">%s%s</label>', esc_attr( $id ), esc_html( $label ), $required_mark );
}

function openbb_render_select_options( array $field ): string {
	$selected = array_map( 'strval', (array) $field['value'] );
	$html     = '';

	if ( ! $field['multiple'] ) {
		$html .= sprintf( '<option value="">%s</option>', esc_html__( 'Choose…', 'open-bulk-buying' ) );
	}

	foreach ( $field['groups'] as $group ) {
		$options = '';
		foreach ( $group['options'] as $option ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option['value'] ),
				in_array( (string) $option['value'], $selected, true ) ? ' selected' : '',
				esc_html( $option['label'] )
			);
		}
		$html .= null === ( $group['label'] ?? null ) ? $options : sprintf( '<optgroup label="%s">%s</optgroup>', esc_attr( $group['label'] ), $options );
	}

	return $html;
}

function openbb_render_chip_groups( array $field, string $id, array $control_attrs ): string {
	$selected = array_map( 'strval', (array) $field['value'] );
	$type     = $field['multiple'] ? 'checkbox' : 'radio';
	$html     = '';
	$index    = 0;

	foreach ( $field['groups'] as $group ) {
		$chips = '';
		foreach ( $group['options'] as $option ) {
			$attrs  = array_merge(
				array(
					'type'               => $type,
					'class'              => 'openbb-chip__input',
					'id'                 => $id . '-' . ( $index++ ),
					'name'               => $field['multiple'] ? $field['name'] . '[]' : $field['name'],
					'value'              => (string) $option['value'],
					'checked'            => in_array( (string) $option['value'], $selected, true ),
					'required'           => $field['required'] && ! $field['multiple'],
					'disabled'           => $field['locked'],
					'data-wp-on--change' => 'openbb/forms::actions.change',
				),
				array( 'data-wp-watch' => $control_attrs['data-wp-watch'] )
			);
			$chips .= sprintf( '<label class="openbb-chip"><input%s><span class="openbb-chip__label">%s</span></label>', openbb_field_attrs( $attrs ), esc_html( $option['label'] ) );
		}

		$html .= null === ( $group['label'] ?? null )
			? '<div class="openbb-chips__options">' . $chips . '</div>'
			: sprintf( '<fieldset class="openbb-chips__group"><legend class="openbb-chips__legend">%s</legend><div class="openbb-chips__options">%s</div></fieldset>', esc_html( $group['label'] ), $chips );
	}

	return $html;
}
