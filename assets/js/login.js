/**
 * Store `openbb/login`: the login form rendered by OPENBB_Login_View (no build step).
 *
 * Steps are switched in place (context.current). With context.link the form asks for an
 * emailed login link instead of a password. Field values and errors live in the
 * `openbb/forms` context of the <form>.
 *
 * REST: POST login, login/link, login/link/verify (inc/rest/frontend/class-openbb-rest-login.php).
 */
import * as interactivity from '@wordpress/interactivity';
import {
	afterRender,
	findField,
	focusField,
	setErrors,
	validate,
} from '@open-bulk-buying/forms';
import { captchaFields, resetCaptcha } from '@open-bulk-buying/captcha';

const { store, getContext, getElement } = interactivity;
// Added in WordPress 6.8; on older versions handlers already run synchronously.
const withSyncEvent = interactivity.withSyncEvent || ( ( fn ) => fn );
const withScope = interactivity.withScope || ( ( fn ) => fn );

const FORMS = 'openbb/forms';

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

const linkBody = ( ctx, login, root ) => ( {
	login,
	redirect_to: ctx.redirect,
	page: ctx.page,
	captcha: captchaFields( root ),
} );

const { state } = store( 'openbb/login', {
	actions: {
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			const form = event.target;
			if ( ctx.busy ) {
				return;
			}

			ctx.notice = '';
			const container = ctx.link
				? form.querySelector( '[data-login-fields]' )
				: form;
			if ( ctx.link ) {
				delete formCtx.errors.password;
			}
			const invalid = Object.keys( validate( container, formCtx ) );
			if ( invalid.length ) {
				ctx.notice = state.messages.fixErrors;
				focusField( form, invalid[ 0 ] );
				return;
			}

			const login = String( formCtx.values.login ).trim();
			ctx.busy = true;
			let leaving = false;
			try {
				if ( ctx.link ) {
					const { ok, data } = yield post(
						'/link',
						linkBody( ctx, login, form )
					);
					if ( ok ) {
						ctx.login = login;
						startCountdown( ctx, data.resend_after );
						go( ctx, 'sent' );
						return;
					}
					if ( data?.data?.errors ) {
						setErrors( formCtx, data.data.errors );
					}
					ctx.notice = data?.message || state.messages.network;
					return;
				}

				const { ok, data } = yield post( '', {
					login,
					password: formCtx.values.password,
					remember: !! formCtx.values.remember,
					redirect_to: ctx.redirect,
					captcha: captchaFields( form ),
				} );
				if ( ok ) {
					// Stay busy while the next page loads.
					leaving = true;
					window.location.assign( data.redirect );
					return;
				}

				const errors = data?.data?.errors;
				if ( errors && typeof errors === 'object' ) {
					setErrors( formCtx, errors );
					focusField( form, Object.keys( errors )[ 0 ] );
				} else if ( data?.code === 'openbb_login_failed' ) {
					// A wrong password is retyped, not edited.
					const input = form.elements.password;
					formCtx.values.password = '';
					if ( input ) {
						input.value = '';
					}
					focusField( form, 'password' );
				}
				ctx.notice = data?.message || state.messages.network;
			} finally {
				if ( ! leaving ) {
					ctx.busy = false;
					resetCaptcha( form );
				}
			}
		} ),

		toggleMethod() {
			const ctx = getContext();
			ctx.link = ! ctx.link;
			ctx.notice = '';
			const { ref } = getElement();
			const field = findField( ref.closest( 'form' ), 'login' );
			afterRender().then( () => field?.querySelector( 'input' )?.focus() );
		},

		back() {
			const ctx = getContext();
			ctx.token = '';
			go( ctx, 'form' );
		},

		*resend() {
			const ctx = getContext();
			if ( ctx.busy || ! ctx.canResend ) {
				return;
			}

			ctx.busy = true;
			ctx.notice = '';
			ctx.status = '';
			const step = getElement().ref.closest( '[data-step]' );
			try {
				const { ok, data } = yield post(
					'/link',
					linkBody( ctx, ctx.login, step )
				);
				if ( ok ) {
					startCountdown( ctx, data.resend_after );
					ctx.status = state.messages.linkResent;
					return;
				}
				ctx.notice = data?.message || state.messages.network;
			} finally {
				ctx.busy = false;
				resetCaptcha( step );
			}
		},

		*confirm() {
			const ctx = getContext();
			if ( ctx.busy ) {
				return;
			}

			ctx.busy = true;
			ctx.notice = '';
			let leaving = false;
			try {
				const { ok, data } = yield post( '/link/verify', {
					token: ctx.token,
				} );
				if ( ok ) {
					leaving = true;
					window.location.assign( data.redirect );
					return;
				}
				ctx.token = '';
				ctx.link = true;
				go( ctx, 'form' );
				ctx.notice = data?.message || state.messages.network;
			} finally {
				if ( ! leaving ) {
					ctx.busy = false;
				}
			}
		},
	},

	callbacks: {
		// Moves focus to a step when the flow switches to it (not on page load).
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

		// The login link token stays in the context; drop it from the address bar so it
		// isn't bookmarked, shared or kept in history.
		cleanUrl() {
			const url = new URL( window.location.href );
			if ( url.searchParams.has( state.queryArg ) ) {
				url.searchParams.delete( state.queryArg );
				window.history.replaceState( window.history.state, '', url );
			}
		},
	},
} );
