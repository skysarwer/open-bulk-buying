/**
 * Store `openbb/forms`: reusable form fields for frontend views (no build step).
 *
 * Fields are rendered by openbb_render_field() (inc/frontend/fields.php) inside an
 * element whose `openbb/forms` context holds `values` and `errors`, both keyed by field
 * name. Inputs are uncontrolled; `actions.change` copies their value into the context.
 *
 * Other stores import the helpers below to validate, show errors and focus fields.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

const FIELD = '[data-openbb-field]';

/**
 * Normalizes a typed/pasted email code and submits once all digits are present.
 *
 * @param {Event}   event
 * @param {Object}  ctx    Field values and errors.
 * @param {number}  length
 * @param {boolean} busy
 */
export function codeInput( event, ctx, length, busy ) {
	const input = event.target;
	const digits = input.value.replace( /\D/g, '' ).slice( 0, length );
	if ( input.value !== digits ) {
		input.value = digits;
	}
	ctx.values.code = digits;
	delete ctx.errors.code;
	if ( digits.length === length && ! busy ) {
		input.form.requestSubmit();
	}
}

const { state } = store( 'openbb/forms', {
	actions: {
		change( event ) {
			const wrapper = event.target.closest( FIELD );
			if ( ! wrapper ) {
				return;
			}
			const ctx = getContext();
			ctx.values[ wrapper.dataset.name ] = readValue(
				wrapper,
				event.target
			);
			delete ctx.errors[ wrapper.dataset.name ];
		},
	},
	callbacks: {
		// aria-invalid follows the field's error; kept client-only so the server never
		// renders an invalid state.
		syncInvalid() {
			const ctx = getContext();
			const { ref } = getElement();
			const name = ref.closest( FIELD )?.dataset.name;
			if ( name && ctx.errors[ name ] ) {
				ref.setAttribute( 'aria-invalid', 'true' );
			} else {
				ref.removeAttribute( 'aria-invalid' );
			}
		},
	},
} );

function readValue( wrapper, target ) {
	switch ( wrapper.dataset.kind ) {
		case 'checkbox':
			return target.checked;
		case 'chips': {
			const inputs = [ ...wrapper.querySelectorAll( 'input' ) ];
			if ( target.type === 'checkbox' ) {
				return inputs
					.filter( ( input ) => input.checked )
					.map( ( input ) => input.value );
			}
			return inputs.find( ( input ) => input.checked )?.value ?? '';
		}
		case 'select':
			return target.multiple
				? [ ...target.selectedOptions ].map(
						( option ) => option.value
				  )
				: target.value;
		default:
			return target.value;
	}
}

const isEmpty = ( value ) =>
	value === null ||
	value === undefined ||
	value === false ||
	( typeof value === 'string' && value.trim() === '' ) ||
	( Array.isArray( value ) && value.length === 0 );

/**
 * Resolves after the pending re-render, e.g. once a step is shown and can take focus.
 *
 * @return {Promise<void>} Resolves on the next frame.
 */
export const afterRender = () =>
	new Promise( ( resolve ) =>
		requestAnimationFrame( () => setTimeout( resolve, 0 ) )
	);

/**
 * The field wrapper for `name` inside `container`.
 *
 * @param {Element} container
 * @param {string}  name
 * @return {Element|null} Wrapper element.
 */
export const findField = ( container, name ) =>
	container.querySelector(
		`${ FIELD }[data-name="${ CSS.escape( name ) }"]`
	);

/**
 * Checks the editable fields inside `container` against the values in `ctx` (an
 * `openbb/forms` context), updates `ctx.errors` for them and returns the new errors.
 * The server validates again; this only saves a round trip.
 *
 * @param {Element} container
 * @param {Object}  ctx
 * @return {Object} Field name => message.
 */
export function validate( container, ctx ) {
	const messages = state.messages;
	const errors = {};

	for ( const wrapper of container.querySelectorAll( FIELD ) ) {
		const { name, kind } = wrapper.dataset;
		if ( 'locked' in wrapper.dataset ) {
			continue;
		}

		const value = ctx.values[ name ];
		let message = '';
		if ( 'required' in wrapper.dataset && isEmpty( value ) ) {
			if ( kind === 'checkbox' ) {
				message = messages.requiredCheck;
			} else if ( kind === 'chips' || kind === 'select' ) {
				message = messages.requiredOne;
			} else {
				message = messages.required;
			}
		} else if ( kind === 'email' && ! isEmpty( value ) ) {
			const input = wrapper.querySelector( 'input' );
			if ( input && ! input.checkValidity() ) {
				message = messages.email;
			}
		}

		if ( message ) {
			errors[ name ] = message;
			ctx.errors[ name ] = message;
		} else {
			delete ctx.errors[ name ];
		}
	}

	return errors;
}

/**
 * Replaces all errors in `ctx`, e.g. with `data.errors` from a REST error response.
 *
 * @param {Object} ctx
 * @param {Object} errors Field name => message.
 */
export function setErrors( ctx, errors ) {
	for ( const name of Object.keys( ctx.errors ) ) {
		if ( ! ( name in errors ) ) {
			delete ctx.errors[ name ];
		}
	}
	for ( const [ name, message ] of Object.entries( errors ) ) {
		ctx.errors[ name ] = String( message );
	}
}

/**
 * Moves focus to a field's first usable control once it's rendered.
 *
 * @param {Element} container
 * @param {string}  name
 */
export async function focusField( container, name ) {
	const control = findField( container, name )?.querySelector(
		'input:not(:disabled), select:not(:disabled), textarea:not(:disabled)'
	);
	if ( ! control ) {
		return;
	}
	await afterRender();
	control.focus();
	if (
		typeof control.select === 'function' &&
		control.type !== 'checkbox' &&
		control.type !== 'radio'
	) {
		control.select();
	}
}
