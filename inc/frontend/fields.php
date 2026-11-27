<?php
/**
 * Reusable form field partials for frontend views, wired to the `openbb/forms` store
 * (assets/js/forms.js). Markup follows: <select> with <optgroup>s,
 * chips as <fieldset>/<legend> groups of native radios or checkboxes, and a star rating
 * as a <fieldset> of native radios.
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
	return array( 'text', 'email', 'tel', 'password', 'textarea', 'select', 'chips', 'stars', 'checkbox' );
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
 *     @type bool   $clearable    Single-choice chips and stars: adds a button that takes the
 *                                answer back, for questions that may be left unanswered
 *                                (a radio can't be unchecked by itself).
 *     @type array  $groups       select, chips and stars: list of [ 'label' => ?string, 'options' => [ [ 'value', 'label' ] ] ].
 *                                A group without label holds ungrouped options and comes first.
 *                                Stars take one group whose values are the ratings, lowest first.
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
			'clearable'    => false,
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
				. openbb_field_clear_button( $field )
				. '</fieldset>' . $error . '</div>';

		case 'stars':
			return $wrapper
				. sprintf( '<fieldset class="openbb-stars" aria-describedby="%s"><legend class="openbb-field__label">%s%s</legend>', esc_attr( implode( ' ', $described ) ), esc_html( $field['label'] ), $required_mark )
				. $help
				. openbb_render_stars( $field, $id, $control_attrs )
				. openbb_field_clear_button( $field )
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
 * The input for a field definition (OPENBB_Field_Schema), localized.
 * Archived options are only listed when they're part of $value.
 *
 * @param mixed $value The stored value: a string, a bool or a list of option keys.
 * @param array $args  Overrides for the field, e.g. locked or id_prefix.
 */
function openbb_field_input( array $definition, $value, array $args = array() ): array {
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
		case OPENBB_Field_Schema::TYPE_BOOLEAN:
			$field['kind']  = 'checkbox';
			$field['value'] = (bool) $value;
			break;

		case OPENBB_Field_Schema::TYPE_OPTIONS:
			$multiple = (bool) $definition['options']['multiple'];
			$selected = array_map( 'strval', (array) $value );
			$groups   = array();
			foreach ( $definition['options']['groups'] as $group ) {
				$options = array();
				foreach ( $group['options'] as $option ) {
					if ( ! $option['archived'] || in_array( (string) $option['key'], $selected, true ) ) {
						$options[] = array(
							'value' => (string) $option['key'],
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

			$kinds = array(
				OPENBB_Field_Schema::STYLE_CHIPS => 'chips',
				OPENBB_Field_Schema::STYLE_STARS => 'stars',
			);

			$field['kind']     = $kinds[ $definition['options']['style'] ] ?? 'select';
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
 * The input for a profile field definition (OPENBB_Profile_Fields).
 */
function openbb_profile_field_input( array $definition, $value, array $args = array() ): array {
	return openbb_field_input( $definition, $value, $args );
}

/**
 * The input for a poll question (OPENBB_Polls). Unlike profile fields, every question may
 * be left unanswered and "No" is an answer of its own: a yes/no question is two choices
 * that start unselected, not a checkbox, and single choices can be taken back.
 *
 * @param mixed $value The member's answer (OPENBB_Poll_Responses::get_answers()), or null.
 */
function openbb_poll_field_input( array $question, $value, array $args = array() ): array {
	if ( OPENBB_Field_Schema::TYPE_BOOLEAN !== $question['type'] ) {
		$field = openbb_field_input( $question, $value, $args );
		if ( in_array( $field['kind'], array( 'chips', 'stars' ), true ) && ! $field['multiple'] ) {
			$field['clearable'] = true;
		}

		return $field;
	}

	$answer = '';
	if ( is_bool( $value ) ) {
		$answer = $value ? OPENBB_Poll_Responses::YES : OPENBB_Poll_Responses::NO;
	}

	return array_merge(
		array(
			'name'      => $question['key'],
			'label'     => openbb_localized( $question['label'] ),
			'help'      => openbb_localized( $question['help'] ),
			'kind'      => 'chips',
			'multiple'  => false,
			'clearable' => true,
			'groups'    => array(
				array(
					'label'   => null,
					'options' => array(
						array(
							'value' => OPENBB_Poll_Responses::YES,
							'label' => __( 'Yes', 'open-bulk-buying' ),
						),
						array(
							'value' => OPENBB_Poll_Responses::NO,
							'label' => __( 'No', 'open-bulk-buying' ),
						),
					),
				),
			),
			'value'     => $answer,
		),
		$args
	);
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

/**
 * A button that takes back the answer of a single-choice field, shown once there is one.
 */
function openbb_field_clear_button( array $field ): string {
	if ( ! $field['clearable'] || $field['multiple'] || $field['locked'] ) {
		return '';
	}

	return sprintf(
		'<button type="button" class="openbb-button openbb-button--link openbb-field__clear" data-wp-on--click="openbb/forms::actions.clear" data-wp-bind--hidden="openbb/forms::!context.values.%s"%s>%s</button>',
		esc_attr( $field['name'] ),
		'' === (string) ( is_scalar( $field['value'] ) ? $field['value'] : '' ) ? ' hidden' : '',
		esc_html__( 'Clear answer', 'open-bulk-buying' )
	);
}

/**
 * A star rating: native radios in reading order, one per rating, each showing the star
 * icon. A star is filled when the selected rating is at least its own. That comes from
 * the field's value (`state.isStarFilled` in the `openbb/forms` store), not from the
 * order of the elements, so it also reads correctly right-to-left.
 */
function openbb_render_stars( array $field, string $id, array $control_attrs ): string {
	$ratings  = $field['groups'][0]['options'] ?? array();
	$selected = (int) ( is_scalar( $field['value'] ) ? $field['value'] : 0 );
	$icon     = openbb_star_icon();
	$html     = '';

	foreach ( $ratings as $index => $option ) {
		$rating = (int) $option['value'];
		$attrs  = array(
			'type'               => 'radio',
			'class'              => 'openbb-star__input',
			'id'                 => $id . '-' . $index,
			'name'               => $field['name'],
			'value'              => (string) $option['value'],
			'checked'            => $rating === $selected,
			'disabled'           => $field['locked'],
			'data-wp-on--change' => 'openbb/forms::actions.change',
			'data-wp-watch'      => $control_attrs['data-wp-watch'],
		);
		$html  .= sprintf(
			'<label class="openbb-star%s" %s data-wp-class--is-filled="openbb/forms::state.isStarFilled"><input%s><span class="openbb-star__icon" aria-hidden="true">%s</span><span class="openbb-sr-only">%s</span></label>',
			$selected >= $rating ? ' is-filled' : '',
			wp_interactivity_data_wp_context(
				array(
					'star'      => $rating,
					'starField' => $field['name'],
				),
				'openbb/forms'
			),
			openbb_field_attrs( $attrs ),
			$icon, // Passed through wp_kses() in openbb_star_icon().
			esc_html(
				sprintf(
					/* translators: 1: rating, 2: highest rating, e.g. "3 of 5 stars". */
					_n( '%1$d of %2$d star', '%1$d of %2$d stars', $rating, 'open-bulk-buying' ),
					$rating,
					count( $ratings )
				)
			)
		);
	}

	return '<div class="openbb-stars__options">' . $html . '</div>';
}

/**
 * The star icon of rating questions, as inline SVG. One icon serves both states: it
 * draws with `currentColor`, and CSS decides whether a star is filled or outlined.
 */
function openbb_star_icon(): string {
	$default = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.5l-5.9 3.1 1.2-6.5-4.8-4.6 6.6-.9z"/></svg>';

	/**
	 * Filters the star icon of rating questions. Return SVG markup that draws with
	 * `currentColor` and sets no fill on its shapes: the same icon is shown outlined
	 * (empty) and filled. Markup outside the SVG allowlist is stripped.
	 *
	 * @param string $svg Inline SVG.
	 */
	$svg = apply_filters( 'openbb_rating_star_svg', $default );
	// wp_kses() lowercases attribute names; SVG's viewBox is case-sensitive outside HTML parsing.
	$svg = is_string( $svg ) ? str_replace( ' viewbox=', ' viewBox=', wp_kses( $svg, openbb_svg_allowed_html() ) ) : '';

	return false !== stripos( $svg, '<svg' ) ? $svg : $default;
}

/**
 * Elements and attributes allowed in filtered icons, for wp_kses().
 */
function openbb_svg_allowed_html(): array {
	$paint = array(
		'class'           => true,
		'fill'            => true,
		'fill-rule'       => true,
		'clip-rule'       => true,
		'opacity'         => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linecap'  => true,
		'stroke-linejoin' => true,
		'transform'       => true,
	);

	return array(
		'svg'      => $paint + array(
			'xmlns'       => true,
			'viewbox'     => true,
			'width'       => true,
			'height'      => true,
			'role'        => true,
			'aria-hidden' => true,
			'focusable'   => true,
		),
		'g'        => $paint,
		'path'     => $paint + array( 'd' => true ),
		'circle'   => $paint + array(
			'cx' => true,
			'cy' => true,
			'r'  => true,
		),
		'ellipse'  => $paint + array(
			'cx' => true,
			'cy' => true,
			'rx' => true,
			'ry' => true,
		),
		'rect'     => $paint + array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'ry'     => true,
		),
		'polygon'  => $paint + array( 'points' => true ),
		'polyline' => $paint + array( 'points' => true ),
		'line'     => $paint + array(
			'x1' => true,
			'y1' => true,
			'x2' => true,
			'y2' => true,
		),
	);
}
