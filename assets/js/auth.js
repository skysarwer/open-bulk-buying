/**
 * Store `openbb/auth`: switches between the login and signup panels rendered by
 * OPENBB_Login_View::auth() (no build step). The panels' own stores trigger it with
 * `openbb/auth::actions.showLogin` and `openbb/auth::actions.showSignup`.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { afterRender } from '@open-bulk-buying/forms';

function show( panel ) {
	const ctx = getContext();
	for ( const key of Object.keys( ctx.show ) ) {
		ctx.show[ key ] = key === panel;
	}
	ctx.focus = true;
}

store( 'openbb/auth', {
	actions: {
		showLogin() {
			show( 'login' );
		},
		showSignup() {
			show( 'signup' );
		},
	},

	callbacks: {
		// After a switch, focus moves to the heading of the panel's visible step.
		focusPanel() {
			const ctx = getContext();
			const { ref } = getElement();
			if ( ! ctx.show[ ref.dataset.panel ] || ! ctx.focus ) {
				return;
			}
			ctx.focus = false;
			afterRender().then( () => {
				const target =
					[ ...ref.querySelectorAll( '.openbb-step__title' ) ].find(
						( heading ) => ! heading.closest( '[hidden]' )
					) || ref;
				target.focus();
			} );
		},
	},
} );
