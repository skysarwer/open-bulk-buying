/**
 * Store `openbb/order`: the member order form rendered by OPENBB_Order_Form_View
 * (no build step).
 *
 * The basket is `state.items` ({ id, qty, own }), autosaved as the member's draft
 * order (or as changes to their submitted order) a moment after each change, one
 * request at a time. `state.products` holds the name, unit, price, container charge
 * and the most the member can order of each product.
 *
 * Row getters (qty, max, lineTotal…) read the product id from the context: `id` on
 * catalog rows, `item.id` on basket lines. Their PHP twins in the view render the page.
 *
 * The basket panel is a <dialog>: shown with show() as a sidebar on wide screens and
 * with showModal() as a sheet on narrow ones.
 *
 * REST: GET/POST openbb/v1/order.
 */
import * as interactivity from '@wordpress/interactivity';
import {
	afterRender,
	focusField,
	setErrors,
	validate,
} from '@open-bulk-buying/forms';

const { store, getContext, getElement } = interactivity;
// Added in WordPress 6.8; on older versions handlers already run synchronously.
const withSyncEvent = interactivity.withSyncEvent || ( ( fn ) => fn );

const FORMS = 'openbb/forms';
const WIDE = '(min-width: 64em)';
const SIDEBAR_KEY = 'openbb-order-sidebar';
const SAVE_DELAY = 600;
const PAGE_STEP = 10;

/* Formatting ------------------------------------------------------------- */

function sprintf( format, ...args ) {
	let next = 0;
	return String( format ).replace(
		/%(?:(\d+)\$)?([sd%])/g,
		( match, position, type ) => {
			if ( type === '%' ) {
				return '%';
			}
			const value = args[ position ? Number( position ) - 1 : next++ ];
			return type === 'd'
				? String( Math.trunc( Number( value ) || 0 ) )
				: String( value ?? '' );
		}
	);
}

let formatter = null;
function money( minor ) {
	if ( ! formatter ) {
		try {
			formatter = new Intl.NumberFormat( state.locale, {
				style: 'currency',
				currency: state.currency,
				minimumFractionDigits: state.digits,
				maximumFractionDigits: state.digits,
			} );
		} catch ( e ) {
			formatter = {
				format: ( n ) =>
					`${ n.toFixed( state.digits ) } ${ state.currency }`,
			};
		}
	}
	return formatter.format( minor / 10 ** state.digits );
}

/**
 * Minor units from what the member typed: "5", "5.5", "5,50". '' is 0; null if invalid.
 *
 * @param {string} text
 * @return {number|null} Amount in minor units.
 */
function parseMoney( text ) {
	const value = String( text ?? '' )
		.trim()
		.replace( /\s/g, '' );
	if ( value === '' ) {
		return 0;
	}
	const pattern =
		state.digits > 0
			? new RegExp( `^\\d{1,7}(?:[.,]\\d{1,${ state.digits }})?$` )
			: /^\d{1,9}$/;
	if ( ! pattern.test( value ) ) {
		return null;
	}
	const [ whole, fraction = '' ] = value.replace( ',', '.' ).split( '.' );
	return (
		Number( whole ) * 10 ** state.digits +
		Number( fraction.padEnd( state.digits, '0' ) || 0 )
	);
}

/* Basket ----------------------------------------------------------------- */

const rowId = () => {
	const ctx = getContext();
	return Number( ctx.item ? ctx.item.id : ctx.id );
};
const findItem = ( id ) => state.items.find( ( item ) => item.id === id );
const productOf = ( id ) =>
	state.products[ id ] || { name: '', unit: '', price: 0, charge: 0, max: 0 };
const qtyOf = ( id ) => findItem( id )?.qty ?? 0;

function totals() {
	let count = 0;
	let subtotal = 0;
	let credit = 0;
	for ( const item of state.items ) {
		const product = productOf( item.id );
		count += item.qty;
		subtotal += item.qty * ( product.price + product.charge );
		credit += item.own ? item.qty * product.charge : 0;
	}
	return { count, subtotal, credit, goods: subtotal - credit };
}

function announce( text ) {
	// A trailing space toggles so the same message is read again.
	state.announcement = state.announcement === text ? `${ text }\u00a0` : text;
}

/**
 * Sets a product's quantity, clamped to 0…max, and schedules a save.
 *
 * @param {number} id
 * @param {number} value
 * @return {number} The quantity now in the basket.
 */
function setQuantity( id, value ) {
	const max = productOf( id ).max;
	const index = state.items.findIndex( ( item ) => item.id === id );
	const before = index >= 0 ? state.items[ index ].qty : 0;
	const qty = Math.min(
		Math.max( 0, Math.floor( Number( value ) || 0 ) ),
		Math.max( max, 0 )
	);
	if ( qty === before ) {
		return before;
	}

	const name = productOf( id ).name;
	if ( qty === 0 ) {
		state.items.splice( index, 1 );
		announce( sprintf( state.messages.removed, name ) );
	} else if ( index < 0 ) {
		state.items.push( { id, qty, own: false } );
		announce( sprintf( state.messages.added, name ) );
	} else {
		state.items[ index ].qty = qty;
	}
	if ( value > max && max > 0 ) {
		announce( sprintf( state.messages.maxReached, max ) );
	}
	schedule();
	return qty;
}

/**
 * After a basket line disappears, keeps focus inside the panel.
 *
 * @param {Element} ref An element of the row that changed.
 */
async function keepFocus( ref ) {
	const panel = ref.closest( '.openbb-basket' );
	if ( ! panel ) {
		return;
	}
	await afterRender();
	if (
		! panel.contains( document.activeElement ) ||
		! document.activeElement.isConnected
	) {
		panel.querySelector( '.openbb-basket__title' )?.focus();
	}
}

/* Saving ----------------------------------------------------------------- */

let timer = 0;
let inFlight = null;
let dirty = false;
let saved = [];

const lines = () =>
	state.items.map( ( item ) => ( {
		product_id: item.id,
		qty: item.qty,
		own_container: !! item.own,
	} ) );

const copyItems = ( items ) => items.map( ( item ) => ( { ...item } ) );

async function request( body, keepalive = false ) {
	let response;
	try {
		response = await fetch( state.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': state.nonce,
			},
			body: JSON.stringify( { cycle_id: state.cycleId, ...body } ),
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

function linesBody() {
	const body = { lines: lines() };
	if ( state.orderId ) {
		body.expected_revision = state.revision;
	}
	return body;
}

function applyOrder( data ) {
	state.orderId = data.id;
	state.status = data.status;
	state.revision = data.revision;
	state.editable = data.editable;
	state.aidStatus = data.aid_status;
	state.aidApproved = data.aid_approved;
	state.notice = '';
	state.canRetry = false;
}

/**
 * Handles a failed save. Returns true when the basket should be saved again.
 *
 * @param {Object} result From request().
 * @return {boolean} Whether to save again.
 */
function failed( result ) {
	state.saveStatus = 'failed';
	if ( result.network ) {
		state.notice = state.messages.network;
		state.canRetry = true;
		dirty = true;
		return false;
	}

	const code = result.data?.code;
	const info = result.data?.data || {};
	state.notice = result.data?.message || state.messages.network;
	switch ( code ) {
		case 'openbb_insufficient_stock': {
			const id = Number( info.product_id );
			if ( state.products[ id ] ) {
				state.products[ id ].max = Math.max(
					0,
					Math.floor( Number( info.max_qty ) || 0 )
				);
				const item = findItem( id );
				if ( item && item.qty > state.products[ id ].max ) {
					setQuantity( id, state.products[ id ].max );
				}
				return true;
			}
			break;
		}
		case 'openbb_order_changed':
			state.conflict = true;
			return false;
		case 'openbb_order_locked':
		case 'openbb_ordering_closed':
		case 'openbb_not_member':
			state.editable = false;
			return false;
	}

	// Anything else: go back to what was last saved.
	state.items.splice( 0, state.items.length, ...copyItems( saved ) );
	return false;
}

function schedule() {
	dirty = true;
	state.saveStatus = 'saving';
	clearTimeout( timer );
	timer = setTimeout( flush, SAVE_DELAY );
}

/**
 * Saves the basket now if it changed; resolves once nothing is left to save.
 *
 * @return {Promise<boolean>} Whether the last save went through.
 */
async function flush() {
	clearTimeout( timer );
	while ( inFlight ) {
		await inFlight;
	}
	if ( ! dirty || state.conflict || ! state.editable ) {
		return ! dirty;
	}

	dirty = false;
	state.saveStatus = 'saving';
	const sent = copyItems( state.items );
	inFlight = request( linesBody() );
	const result = await inFlight;
	inFlight = null;

	if ( result.ok && result.data ) {
		applyOrder( result.data );
		saved = sent;
		state.saveStatus = dirty ? 'saving' : 'saved';
		if ( dirty ) {
			return flush();
		}
		return true;
	}
	if ( failed( result ) ) {
		dirty = true;
		return flush();
	}
	return false;
}

/* Panel ------------------------------------------------------------------ */

function setPanel( open ) {
	if ( state.wide ) {
		state.sidebarOpen = open;
		try {
			window.localStorage.setItem( SIDEBAR_KEY, open ? '1' : '0' );
		} catch ( e ) {}
	} else {
		state.drawerOpen = open;
	}
	if ( ! open && state.step === 'done' ) {
		state.step = 'basket';
	}
}

async function focusTitle() {
	await afterRender();
	document
		.getElementById( `${ state.panelId }-title` )
		?.focus( { preventScroll: state.wide } );
}

/* Store ------------------------------------------------------------------ */

const { state } = store( 'openbb/order', {
	state: {
		// Per row.
		get qty() {
			return qtyOf( rowId() );
		},
		get max() {
			return productOf( rowId() ).max;
		},
		get inBasket() {
			return qtyOf( rowId() ) > 0;
		},
		get canDecrement() {
			return state.editable && qtyOf( rowId() ) > 0;
		},
		get canIncrement() {
			const id = rowId();
			return state.editable && qtyOf( id ) < productOf( id ).max;
		},
		get lineTotal() {
			const id = rowId();
			const product = productOf( id );
			return money( qtyOf( id ) * ( product.price + product.charge ) );
		},
		get isSoldOut() {
			const id = rowId();
			return productOf( id ).max <= 0 && qtyOf( id ) <= 0;
		},
		get stockNote() {
			const id = rowId();
			const max = productOf( id ).max;
			if ( max <= 0 && qtyOf( id ) <= 0 ) {
				return state.messages.soldOut;
			}
			return max > 0 && max <= state.lowStock
				? sprintf( state.messages.lowStock, max )
				: '';
		},
		get name() {
			return productOf( rowId() ).name;
		},
		get priceLabel() {
			const product = productOf( rowId() );
			return sprintf(
				state.messages.priceLabel,
				money( product.price + product.charge ),
				product.unit
			);
		},
		get qtyLabel() {
			return sprintf(
				state.messages.qtyLabel,
				productOf( rowId() ).name
			);
		},
		get incLabel() {
			return sprintf(
				state.messages.incLabel,
				productOf( rowId() ).name
			);
		},
		get decLabel() {
			return sprintf(
				state.messages.decLabel,
				productOf( rowId() ).name
			);
		},
		get removeLabel() {
			return sprintf(
				state.messages.removeLabel,
				productOf( rowId() ).name
			);
		},
		get hasCharge() {
			return productOf( rowId() ).charge > 0;
		},
		get own() {
			return !! findItem( rowId() )?.own;
		},
		get ownLabel() {
			return sprintf(
				state.messages.ownLabel,
				money( productOf( rowId() ).charge )
			);
		},

		// Totals.
		get count() {
			return totals().count;
		},
		get countLabel() {
			const { count } = totals();
			return sprintf(
				count === 1
					? state.messages.itemsOne
					: state.messages.itemsOther,
				count
			);
		},
		get hasItems() {
			return state.items.length > 0;
		},
		get isEmpty() {
			return state.items.length === 0;
		},
		get subtotalText() {
			return money( totals().subtotal );
		},
		get hasCredit() {
			return totals().credit > 0;
		},
		get creditText() {
			return money( -totals().credit );
		},
		get hasAidApproved() {
			return state.aidApproved > 0;
		},
		get aidText() {
			return money(
				-Math.min( state.aidApproved, Math.max( 0, totals().goods ) )
			);
		},
		get hasDonation() {
			return state.donation > 0;
		},
		get donationText() {
			return money( state.donation );
		},
		get totalText() {
			const { goods } = totals();
			return money(
				Math.max( 0, goods - state.aidApproved ) + state.donation
			);
		},
		get hasAidPending() {
			return state.aidStatus === 'pending';
		},

		// Order and panel.
		get isSubmitted() {
			return state.status === 'submitted';
		},
		get locked() {
			return ! state.editable;
		},
		get canFinalize() {
			return state.editable && state.items.length > 0 && ! state.conflict;
		},
		get panelOpen() {
			return state.wide ? state.sidebarOpen : state.drawerOpen;
		},
		get sidebarShown() {
			return state.wide && state.sidebarOpen;
		},
		get isBasketStep() {
			return state.step === 'basket';
		},
		get isFinalizeStep() {
			return state.step === 'finalize';
		},
		get isDoneStep() {
			return state.step === 'done';
		},
		get saveText() {
			switch ( state.saveStatus ) {
				case 'saving':
					return state.messages.saving;
				case 'saved':
					return state.status === 'submitted'
						? state.messages.savedOrder
						: state.messages.saved;
				case 'failed':
					return state.messages.saveFailed;
			}
			return '';
		},
	},

	actions: {
		increment() {
			const id = rowId();
			setQuantity( id, qtyOf( id ) + 1 );
		},
		decrement() {
			const id = rowId();
			const { ref } = getElement();
			if ( setQuantity( id, qtyOf( id ) - 1 ) === 0 ) {
				keepFocus( ref );
			}
		},
		stepKey: withSyncEvent( function ( event ) {
			if ( state.locked ) {
				return;
			}
			const id = rowId();
			const qty = qtyOf( id );
			const max = productOf( id ).max;
			let next;
			switch ( event.key ) {
				case 'ArrowUp':
					next = qty + 1;
					break;
				case 'ArrowDown':
					next = qty - 1;
					break;
				case 'PageUp':
					next = qty + PAGE_STEP;
					break;
				case 'PageDown':
					next = qty - PAGE_STEP;
					break;
				case 'Home':
					next = 0;
					break;
				case 'End':
					next = max;
					break;
				case 'Enter':
					next = Number( event.target.value ) || 0;
					break;
				default:
					return;
			}
			event.preventDefault();
			const input = event.target;
			const result = setQuantity( id, Math.min( next, max ) );
			input.value = String( result );
			if ( result === 0 ) {
				keepFocus( input );
			}
		} ),
		typeQty( event ) {
			const input = event.target;
			const digits = input.value.replace( /\D/g, '' ).slice( 0, 3 );
			if ( input.value !== digits ) {
				input.value = digits;
			}
		},
		setQty( event ) {
			if ( state.locked ) {
				return;
			}
			const input = event.target;
			const id = rowId();
			const result = setQuantity( id, Number( input.value ) || 0 );
			// The bound value may not change (e.g. typed more than available).
			input.value = String( result );
			if ( result === 0 ) {
				keepFocus( input );
			}
		},
		selectQty( event ) {
			if ( ! state.locked ) {
				event.target.select();
			}
		},
		toggleOwn( event ) {
			const item = findItem( rowId() );
			if ( item ) {
				item.own = event.target.checked;
				schedule();
			}
		},
		remove() {
			const { ref } = getElement();
			setQuantity( rowId(), 0 );
			keepFocus( ref );
		},

		*finalize() {
			state.step = 'finalize';
			state.notice = state.conflict ? state.notice : '';
			yield focusTitle();
			flush();
		},
		*backToBasket() {
			state.step = 'basket';
			yield focusTitle();
		},
		syncDetails() {
			const values = getContext( FORMS ).values;
			const donation = parseMoney( values.donation );
			state.donation = donation ?? 0;
		},
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			if ( state.busy || state.locked ) {
				return;
			}
			const form = event.target;
			const formCtx = getContext( FORMS );
			const values = formCtx.values;
			const messages = state.messages;

			const errors = validate( form, formCtx );
			const donation = parseMoney( values.donation );
			if ( donation === null ) {
				errors.donation = messages.donation;
			}
			const aidOpen =
				state.aidStatus !== 'approved' &&
				state.aidStatus !== 'declined';
			const reason = String( values.aid_reason ?? '' ).trim();
			if ( aidOpen && values.aid && reason === '' ) {
				errors.aid_reason = messages.aidReason;
			}
			setErrors( formCtx, errors );
			if ( ! state.hasItems ) {
				state.notice = messages.emptyBasket;
				return;
			}
			const invalid = Object.keys( errors );
			if ( invalid.length ) {
				state.notice = messages.fixErrors;
				yield focusField( form, invalid[ 0 ] );
				return;
			}

			state.busy = true;
			state.notice = '';
			yield flush();
			if ( state.conflict || state.locked ) {
				state.busy = false;
				return;
			}

			const wasSubmitted = state.isSubmitted;
			const body = {
				...linesBody(),
				donation: donation ?? 0,
				member_note: String( values.member_note ?? '' ),
				submit: ! wasSubmitted,
				commit: !! values.commit,
			};
			if ( aidOpen ) {
				body.aid_request = !! values.aid;
				body.aid_reason = values.aid ? reason : '';
			}
			state.saveStatus = 'saving';
			const result = yield request( body );
			state.busy = false;

			if ( result.ok && result.data ) {
				applyOrder( result.data );
				saved = copyItems( state.items );
				state.donation = result.data.donation;
				state.saveStatus = 'saved';
				state.doneText = wasSubmitted
					? messages.doneUpdated
					: messages.doneSubmitted;
				state.step = 'done';
				yield focusTitle();
				return;
			}

			const fieldErrors = result.data?.data?.errors;
			if ( fieldErrors && ! fieldErrors.lines ) {
				state.saveStatus = 'failed';
				setErrors( formCtx, fieldErrors );
				state.notice = messages.fixErrors;
				yield focusField( form, Object.keys( fieldErrors )[ 0 ] );
				return;
			}
			if ( failed( result ) ) {
				dirty = true;
				flush();
			}
		} ),

		togglePanel() {
			setPanel( ! state.panelOpen );
			if ( state.panelOpen ) {
				focusTitle();
			}
		},
		openPanel() {
			setPanel( true );
			focusTitle();
		},
		closePanel() {
			setPanel( false );
		},
		panelClosed() {
			const { ref } = getElement();
			// Closed only to switch between sidebar and sheet.
			if ( ref.open ) {
				return;
			}
			if ( state.panelOpen ) {
				setPanel( false );
			}
		},
		backdropClick( event ) {
			const { ref } = getElement();
			if ( event.target === ref && ref.matches( ':modal' ) ) {
				setPanel( false );
			}
		},
		reload() {
			window.location.reload();
		},
		retry() {
			state.notice = '';
			state.canRetry = false;
			dirty = true;
			flush();
		},
	},

	callbacks: {
		init() {
			saved = copyItems( state.items );

			const query = window.matchMedia( WIDE );
			state.wide = query.matches;
			query.addEventListener( 'change', ( event ) => {
				state.wide = event.matches;
			} );
			try {
				state.sidebarOpen =
					window.localStorage.getItem( SIDEBAR_KEY ) !== '0';
			} catch ( e ) {}

			// Leaving with unsaved changes: send them along with the page unload.
			window.addEventListener( 'pagehide', () => {
				if (
					dirty &&
					! inFlight &&
					state.editable &&
					! state.conflict
				) {
					clearTimeout( timer );
					dirty = false;
					request( linesBody(), true );
				}
			} );
		},
		syncPanel() {
			const { ref } = getElement();
			const open = state.panelOpen;
			const wide = state.wide;
			if ( ! open ) {
				if ( ref.open ) {
					ref.close();
				}
				return;
			}
			const modal = ref.matches( ':modal' );
			if ( ref.open && modal === ! wide ) {
				return;
			}
			if ( ref.open ) {
				ref.close();
			}
			if ( wide ) {
				ref.show();
			} else {
				ref.showModal();
			}
		},
	},
} );
