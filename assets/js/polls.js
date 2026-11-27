/**
 * Store `openbb/polls`: poll forms rendered by OPENBB_Polls_View (no build step).
 *
 * Each poll is an element with its own context ({ pollId, responded, busy, notice,
 * status, confirmation, confirmed }) holding a form whose fields use the `openbb/forms`
 * context. Submitting sends the whole form: answers replace the member's earlier ones,
 * and an empty answer is "not answered". Nothing is required.
 *
 * Once saved, a poll with a `confirmation` (the polls view) shows it in place of the
 * form until the member goes back to their answers. Others (the order thank-you page)
 * show the status under the form.
 *
 * REST: POST openbb/v1/poll/{id}.
 */
import * as interactivity from '@wordpress/interactivity';
import { afterRender, focusField, setErrors } from '@open-bulk-buying/forms';

const { store, getContext, getElement } = interactivity;
// Added in WordPress 6.8; on older versions handlers already run synchronously.
const withSyncEvent = interactivity.withSyncEvent || ( ( fn ) => fn );

const FORMS = 'openbb/forms';

/**
 * Whether a field value means "not answered".
 *
 * @param {*} value
 * @return {boolean} True for '', null, undefined and empty lists.
 */
export const isBlank = ( value ) =>
	value === null ||
	value === undefined ||
	( typeof value === 'string' && value.trim() === '' ) ||
	( Array.isArray( value ) && value.length === 0 );

/**
 * Saves a member's answers to a poll.
 *
 * @param {Object} api         { restUrl, nonce }.
 * @param {number} pollId
 * @param {Object} answers     Question key => value.
 * @return {Promise<Object>} { ok, network, data }; data is the response body.
 */
export async function saveAnswers( api, pollId, answers ) {
	let response;
	try {
		response = await fetch( `${ api.restUrl }/${ pollId }`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': api.nonce,
			},
			body: JSON.stringify( { answers } ),
		} );
	} catch ( e ) {
		return { ok: false, network: true, data: null };
	}

	let data = null;
	try {
		data = await response.json();
	} catch ( e ) {}

	return { ok: response.ok, network: false, data };
}

/**
 * Moves focus to `selector` inside a poll once it's rendered.
 *
 * @param {Element|null} poll
 * @param {string}       selector
 */
async function focusIn( poll, selector ) {
	await afterRender();
	poll?.querySelector( selector )?.focus();
}

const { state } = store( 'openbb/polls', {
	actions: {
		// A changed answer isn't saved yet, so "saved" no longer describes the form.
		touch() {
			const ctx = getContext();
			ctx.status = '';
		},
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const ctx = getContext();
			const formCtx = getContext( FORMS );
			if ( ctx.busy ) {
				return;
			}
			const form = event.target;
			const messages = state.messages;
			const answers = { ...formCtx.values };

			ctx.status = '';
			if (
				! ctx.responded &&
				Object.values( answers ).every( isBlank )
			) {
				ctx.notice = messages.empty;
				return;
			}

			ctx.busy = true;
			ctx.notice = '';
			const result = yield saveAnswers( state, ctx.pollId, answers );
			ctx.busy = false;

			if ( result.ok && result.data ) {
				setErrors( formCtx, {} );
				ctx.responded = !! result.data.responded;
				ctx.status = ctx.responded ? messages.saved : messages.cleared;
				if ( ctx.confirmation ) {
					ctx.confirmed = true;
					yield focusIn(
						form.closest( '.openbb-poll' ),
						'.openbb-poll__confirmation .openbb-notice'
					);
				}
				return;
			}

			const fieldErrors = result.data?.data?.errors;
			if ( fieldErrors ) {
				setErrors( formCtx, fieldErrors );
				ctx.notice = messages.fixErrors;
				yield focusField( form, Object.keys( fieldErrors )[ 0 ] );
				return;
			}
			ctx.notice = result.network
				? messages.network
				: result.data?.message || messages.network;
		} ),
		// Back from the confirmation to the form, which still holds the saved answers.
		*edit() {
			const ctx = getContext();
			const poll = getElement().ref.closest( '.openbb-poll' );
			ctx.confirmed = false;
			yield focusIn( poll, '.openbb-poll__title' );
		},
	},
} );
