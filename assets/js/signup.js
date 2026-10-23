/**
 * Store `openbb/signup`: the signup flow rendered by OPENBB_Signup_View (no build step).
 *
 * Steps are switched in place (context.current); the server rendered the first one.
 * Field values and errors live in the `openbb/forms` context of each <form>.
 *
 * REST: POST signup, signup/resend, signup/verify (inc/rest/frontend/class-openbb-rest-signup.php).
 */
import * as interactivity from '@wordpress/interactivity';
import {
	afterRender,
	findField,
	focusField,
	setErrors,
	validate,
} from '@open-bulk-buying/forms';

const { store, getContext, getElement } = interactivity;
// Added in WordPress 6.8; on older versions handlers already run synchronously.
const withSyncEvent = interactivity.withSyncEvent || ( ( fn ) => fn );
const withScope = interactivity.withScope || ( ( fn ) => fn );

const FORMS = 'openbb/forms';

const currentStep = ( ctx ) =>
	Object.keys( ctx.current ).find( ( step ) => ctx.current[ step ] );

function go( ctx, step, focusHeading = true ) {
	for ( const key of Object.keys( ctx.current ) ) {
		ctx.current[ key ] = key === step;
	}
	ctx.focus = focusHeading;
	ctx.notice = '';
	ctx.status = '';
}

function startCountdown( ctx, seconds ) {
	ctx.resendIn = Math.max( 0, Number( seconds ) || 0 );
	ctx.canResend = ctx.resendIn === 0;
}

async function post( path, body ) {
	let response;
	try {
		response = await fetch( state.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': state.nonce,
			},
			body: JSON.stringify( body ),
		} );
	} catch ( e ) {
		return { ok: false, data: null };
	}

	let data = null;
	try {
		data = await response.json();
	} catch ( e ) {}

	return { ok: response.ok, data };
}

/**
 * The REST body for the signup form's values.
 *
 * @param {Object}          values Proxy of the form's context values.
 * @param {Object}          ctx    Signup context.
 * @param {HTMLFormElement} form
 * @return {Object} Request body.
 */
function signupBody( values, ctx, form ) {
	const account = {};
	const profile = {};
	for ( const [ name, value ] of Object.entries(
		JSON.parse( JSON.stringify( values ) )
	) ) {
		if ( name === state.rulesField ) {
			continue;
		}
		( state.accountFields.includes( name ) ? account : profile )[ name ] =
			value;
	}

	return {
		account,
		profile,
		rules_version: values[ state.rulesField ] ? ctx.rulesVersion : 0,
		[ state.honeypot ]: form.elements[ state.honeypot ]?.value || '',
	};
}

/**
 * Shows a REST error: the message as the notice, field errors inline, and focus on the
 * first invalid field (switching to its step).
 *
 * @param {Object}          ctx     Signup context.
 * @param {Object}          formCtx Forms context.
 * @param {HTMLFormElement} form
 * @param {Object|null}     error   REST error body.
 */
function showError( ctx, formCtx, form, error ) {
	const errors = error?.data?.errors;
	if ( errors && typeof errors === 'object' ) {
		setErrors( formCtx, errors );
		const wrapper = Object.keys( errors )
			.map( ( name ) => findField( form, name ) )
			.find( Boolean );
		const step = wrapper?.closest( '[data-step]' )?.dataset.step;
		if ( step && step !== currentStep( ctx ) ) {
			go( ctx, step, false );
		}
		if ( wrapper ) {
			focusField( form, wrapper.dataset.name );
		}
	}
	ctx.notice = error?.message || state.messages.network;
}

const { state } = store( 'openbb/signup', {
	actions: {
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			const form = event.target;
			if ( ctx.busy ) {
				return;
			}

			const step = currentStep( ctx );
			const section = form.querySelector( `[data-step="${ step }"]` );
			ctx.notice = '';
			const errors = validate( section, formCtx );
			const invalid = Object.keys( errors );
			if ( invalid.length ) {
				ctx.notice = state.messages.fixErrors;
				focusField( section, invalid[ 0 ] );
				return;
			}

			const index = ctx.steps.indexOf( step );
			if ( index < ctx.steps.length - 1 ) {
				go( ctx, ctx.steps[ index + 1 ] );
				return;
			}

			ctx.busy = true;
			try {
				const { ok, data } = yield post(
					'',
					signupBody( formCtx.values, ctx, form )
				);
				if ( ! ok ) {
					showError( ctx, formCtx, form, data );
					return;
				}
				if ( data.step === 'code' ) {
					ctx.email = data.email;
					startCountdown( ctx, data.resend_after );
					go( ctx, 'code' );
				} else {
					ctx.applicant = data.status === 'applicant';
					go( ctx, 'done' );
				}
			} finally {
				ctx.busy = false;
			}
		} ),

		back() {
			const ctx = getContext();
			const step = currentStep( ctx );
			const index = ctx.steps.indexOf( step );
			// From the code step, go back to the details to change the email.
			go( ctx, index > 0 ? ctx.steps[ index - 1 ] : ctx.steps[ 0 ] );
		},

		codeInput( event ) {
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			const input = event.target;
			const digits = input.value
				.replace( /\D/g, '' )
				.slice( 0, state.codeLength );
			if ( input.value !== digits ) {
				input.value = digits;
			}
			formCtx.values.code = digits;
			delete formCtx.errors.code;
			if ( digits.length === state.codeLength && ! ctx.busy ) {
				input.form.requestSubmit();
			}
		},

		verify: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			const form = event.target;
			if ( ctx.busy ) {
				return;
			}

			const code = formCtx.values.code || '';
			if ( code.length !== state.codeLength ) {
				formCtx.errors.code = state.messages.codeRequired;
				focusField( form, 'code' );
				return;
			}

			ctx.busy = true;
			ctx.notice = '';
			ctx.status = '';
			try {
				const { ok, data } = yield post( '/verify', { code } );
				if ( ok ) {
					state.nonce = data.nonce;
					ctx.applicant = data.status === 'applicant';
					go( ctx, 'done' );
					return;
				}
				if ( data?.code === 'openbb_code_invalid' ) {
					formCtx.errors.code = data.message;
					focusField( form, 'code' );
				} else {
					ctx.notice = data?.message || state.messages.network;
				}
			} finally {
				ctx.busy = false;
			}
		} ),

		*resend() {
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			if ( ctx.busy || ! ctx.canResend ) {
				return;
			}

			ctx.busy = true;
			ctx.notice = '';
			ctx.status = '';
			try {
				const { ok, data } = yield post( '/resend', {} );
				if ( ok ) {
					startCountdown( ctx, data.resend_after );
					formCtx.values.code = '';
					delete formCtx.errors.code;
					ctx.status = state.messages.codeResent;
					const { ref } = getElement();
					const input = ref.form?.elements.code;
					if ( input ) {
						input.value = '';
						input.focus();
					}
					return;
				}
				if ( data?.data?.resend_after ) {
					startCountdown( ctx, data.data.resend_after );
				}
				ctx.notice = data?.message || state.messages.network;
			} finally {
				ctx.busy = false;
			}
		},
	},

	callbacks: {
		// Moves focus to a step when the flow switches to it (not on page load), so
		// keyboard and screen reader users land at its start, or on its main input.
		focusStep() {
			const ctx = getContext();
			const { ref } = getElement();
			const section = ref.closest( '[data-step]' );
			if ( ! ctx.current[ section.dataset.step ] || ! ctx.focus ) {
				return;
			}
			ctx.focus = false;
			const target = section.querySelector( '[data-autofocus]' ) || ref;
			afterRender().then( () => target.focus() );
		},

		countdown() {
			const ctx = getContext();
			if ( ctx.resendIn <= 0 ) {
				return;
			}
			const timer = setTimeout(
				withScope( () => {
					ctx.resendIn -= 1;
					ctx.canResend = ctx.resendIn <= 0;
				} ),
				1000
			);
			return () => clearTimeout( timer );
		},
	},
} );
