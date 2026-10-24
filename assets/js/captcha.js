/**
 * Bot protection widgets printed by OPENBB_Captcha::field() (no build step).
 *
 * Store `openbb/captcha` renders Cloudflare Turnstile into [data-openbb-turnstile] once the
 * widget is visible (forms switch steps in place, and Turnstile needs a visible container),
 * after hydration so the runtime never removes its iframe. Other providers' widgets only
 * need to listen for the `openbb:captcha-reset` event on the wrapper.
 *
 * The login and signup stores send captchaFields() with their requests and call
 * resetCaptcha() after each response: a widget response can only be used once.
 */
import { store, getElement } from '@wordpress/interactivity';

const TURNSTILE =
	'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
const RESET = 'openbb:captcha-reset';

let loading = null;

function loadTurnstile() {
	if ( window.turnstile ) {
		return Promise.resolve( window.turnstile );
	}
	if ( ! loading ) {
		loading = new Promise( ( resolve, reject ) => {
			const script = document.createElement( 'script' );
			script.src = TURNSTILE;
			script.async = true;
			script.onload = () => resolve( window.turnstile );
			script.onerror = () => {
				loading = null;
				script.remove();
				reject( new Error( 'Turnstile failed to load' ) );
			};
			document.head.appendChild( script );
		} );
	}
	return loading;
}

const boxes = ( root ) =>
	root ? root.querySelectorAll( '[data-openbb-captcha]' ) : [];

/**
 * The form controls inside the visible widget under root, name => value.
 *
 * @param {Element} root Form or step containing the widget.
 * @return {Object} Widget response fields; empty when there's no widget.
 */
export function captchaFields( root ) {
	const fields = {};
	for ( const box of boxes( root ) ) {
		if ( ! box.offsetParent ) {
			continue;
		}
		for ( const control of box.querySelectorAll( 'input, textarea, select' ) ) {
			if ( control.name ) {
				fields[ control.name ] = control.value;
			}
		}
	}
	return fields;
}

/**
 * Asks the widgets under root for a fresh response.
 *
 * @param {Element} root Form or step containing the widget.
 */
export function resetCaptcha( root ) {
	for ( const box of boxes( root ) ) {
		box.dispatchEvent( new CustomEvent( RESET ) );
	}
}

store( 'openbb/captcha', {
	callbacks: {
		init() {
			const { ref } = getElement();
			const target = ref.querySelector( '[data-openbb-turnstile]' );
			if ( ! target ) {
				return;
			}

			let id = null;
			let removed = false;
			const observer = new window.IntersectionObserver( ( entries ) => {
				if ( ! entries.some( ( entry ) => entry.isIntersecting ) ) {
					return;
				}
				observer.disconnect();
				loadTurnstile()
					.then( ( turnstile ) => {
						if ( removed ) {
							return;
						}
						id = turnstile.render( target, {
							sitekey: target.dataset.sitekey,
							action: target.dataset.action,
						} );
					} )
					.catch( () => {
						// The server refuses the request with a clear message; nothing to show here.
					} );
			} );
			observer.observe( target );

			const reset = () => {
				if ( id !== null && window.turnstile ) {
					window.turnstile.reset( id );
				}
			};
			ref.addEventListener( RESET, reset );

			return () => {
				removed = true;
				observer.disconnect();
				ref.removeEventListener( RESET, reset );
				if ( id !== null && window.turnstile ) {
					window.turnstile.remove( id );
				}
			};
		},
	},
} );
