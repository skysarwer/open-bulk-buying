import {
	Button,
	ComboboxControl,
	Notice,
	SelectControl,
	TextareaControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';
import { closeSmall } from '@wordpress/icons';

import { ErrorNotice, RecordLink, SidePanel } from '../../components';
import { discardUnsavedChanges, useDebouncedValue, useUnsavedChanges } from '../../hooks';
import { request } from '../../utils/api';
import { can, config } from '../../utils/config';
import { formatMoney, formatQty, toMajor, toMinor } from '../../utils/format';

const EPSILON = 0.0005;

let nextKey = 1;

const toLine = ( line ) => ( {
	key: `line-${ line.id }`,
	id: line.id,
	product_id: line.product_id,
	product_name: line.product_name,
	brand: line.brand,
	unit_label: line.unit_label,
	unit_price: line.unit_price,
	container_charge: line.container_charge,
	own_container: line.own_container,
	qty: line.qty_requested,
	original_qty: line.qty_requested,
	original_own: line.own_container,
	qty_prepared: line.qty_prepared,
	qty_final: line.qty_final,
	is_prepared: line.is_prepared,
	max_qty: line.max_qty,
} );

const fromProduct = ( product ) => ( {
	key: `new-${ nextKey++ }`,
	id: 0,
	product_id: product.id,
	product_name: product.name,
	brand: product.brand,
	unit_label: product.unit_label,
	unit_price: product.price,
	container_charge: product.container_charge,
	own_container: false,
	qty: 1,
	is_prepared: false,
	max_qty: product.max_qty,
} );

/** The quantity that counts: final, else prepared, else requested. */
const effectiveQty = ( line ) =>
	line.qty_final ?? line.qty_prepared ?? ( Number( line.qty ) || 0 );

const lineTotal = ( line ) =>
	Math.round( effectiveQty( line ) * ( line.unit_price + line.container_charge ) );

const lineCredit = ( line ) =>
	line.own_container ? Math.round( effectiveQty( line ) * line.container_charge ) : 0;

const isLineChanged = ( line ) =>
	! line.id ||
	( Number( line.qty ) || 0 ) !== line.original_qty ||
	line.own_container !== line.original_own;

function ProductPicker( { orderId, excluded, onPick } ) {
	const [ search, setSearch ] = useState( '' );
	const [ products, setProducts ] = useState( [] );
	const [ error, setError ] = useState( null );
	const debounced = useDebouncedValue( search );

	useEffect( () => {
		let current = true;
		request( '/order-products', { query: { order_id: orderId, search: debounced } } )
			.then( ( items ) => current && setProducts( items ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ orderId, debounced ] );

	const options = products
		.filter( ( product ) => ! excluded.includes( product.id ) )
		.map( ( product ) => ( {
			value: product.id,
			label: sprintf(
				/* translators: 1: product name, 2: unit, 3: price, 4: quantity available. */
				__( '%1$s (%2$s) — %3$s — %4$s available', 'open-bulk-buying' ),
				product.brand ? `${ product.name }, ${ product.brand }` : product.name,
				product.unit_label,
				formatMoney( product.price ),
				formatQty( product.max_qty )
			),
			disabled: product.max_qty < 1,
		} ) );

	return (
		<>
			<ErrorNotice error={ error } />
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Add a product', 'open-bulk-buying' ) }
				help={ __( 'Only active products with stock available can be added.', 'open-bulk-buying' ) }
				value={ null }
				options={ options }
				onFilterValueChange={ setSearch }
				onChange={ ( id ) => {
					const product = products.find( ( item ) => item.id === id );
					if ( product && product.max_qty >= 1 ) {
						onPick( product );
					}
				} }
			/>
		</>
	);
}

function LineRow( { line, isLocked, onChange, onRemove } ) {
	const qty = Number( line.qty ) || 0;
	const overLimit = ! line.is_prepared && qty > line.max_qty + EPSILON;
	const readOnly = isLocked || line.is_prepared;

	return (
		<tr className={ overLimit ? 'is-invalid' : undefined }>
			<td>
				<strong>{ line.product_name }</strong>
				{ line.brand && <div className="openbb-muted">{ line.brand }</div> }
				<div className="openbb-muted">
					{ formatMoney( line.unit_price ) } / { line.unit_label }
					{ line.container_charge > 0 &&
						` + ${ formatMoney( line.container_charge ) } ${ __( 'container', 'open-bulk-buying' ) }` }
				</div>
			</td>
			<td className="openbb-lines__qty">
				{ readOnly ? (
					<>
						{ formatQty( qty ) }
						{ line.is_prepared && (
							<div className="openbb-muted">
								{ /* translators: %s: prepared quantity. */ }
								{ sprintf( __( 'Prepared: %s', 'open-bulk-buying' ), formatQty( line.qty_final ?? line.qty_prepared ) ) }
							</div>
						) }
					</>
				) : (
					<>
						<NumberControl
							__next40pxDefaultSize
							label={ __( 'Quantity', 'open-bulk-buying' ) }
							hideLabelFromVision
							min={ 0 }
							max={ Math.max( Math.floor( line.max_qty ), line.original_qty || 0 ) }
							step={ 1 }
							value={ line.qty }
							onChange={ ( value ) => onChange( { qty: value ?? '' } ) }
						/>
						<div className={ overLimit ? 'openbb-error-text' : 'openbb-muted' }>
							{ /* translators: %s: maximum quantity. */ }
							{ sprintf( __( 'Max %s', 'open-bulk-buying' ), formatQty( line.max_qty ) ) }
						</div>
					</>
				) }
			</td>
			<td>
				{ line.container_charge > 0 && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Own container', 'open-bulk-buying' ) }
						checked={ line.own_container }
						disabled={ readOnly }
						onChange={ ( own ) => onChange( { own_container: own } ) }
					/>
				) }
			</td>
			<td className="openbb-lines__total">
				{ formatMoney( lineTotal( line ) - lineCredit( line ) ) }
			</td>
			<td>
				{ ! readOnly && (
					<Button
						icon={ closeSmall }
						size="small"
						label={ __( 'Remove', 'open-bulk-buying' ) }
						onClick={ onRemove }
					/>
				) }
			</td>
		</tr>
	);
}

/**
 * Views and edits one order. Quantities are capped by what the order may hold
 * (stock on hand minus other orders' reservations); the server re-checks on save.
 *
 * @param {Object} props
 */
export default function OrderEditor( { orderId, onClose, onSaved } ) {
	const [ order, setOrder ] = useState( null );
	const [ lines, setLines ] = useState( [] );
	const [ status, setStatus ] = useState( 'draft' );
	const [ donationInput, setDonationInput ] = useState( '' );
	const donation = Math.max( 0, toMinor( donationInput ) );
	const [ staffNote, setStaffNote ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ confirmation, setConfirmation ] = useState( null );

	const load = ( item ) => {
		setOrder( item );
		setLines( item.lines.map( toLine ) );
		setStatus( item.status );
		setDonationInput( String( toMajor( item.donation ) ) );
		setStaffNote( item.staff_note );
		setReason( '' );
		setConfirmation( null );
	};

	useEffect( () => {
		request( `/orders/${ orderId }` ).then( load ).catch( setError );
	}, [ orderId ] );

	const visibleLines = lines.filter( ( line ) => ! line.removed );
	const linesChanged = lines.some(
		( line ) => ( line.removed && line.id ) || ( ! line.removed && isLineChanged( line ) )
	);
	const changed =
		order &&
		( linesChanged || status !== order.status || donation !== order.donation );
	const noteChanged = order && staffNote !== order.staff_note;
	const needsReason =
		order && changed && order.reason_required_statuses.includes( status );
	const canEdit = can( 'openbb_edit_orders' ) && order?.can_edit;
	const isLocked = ! order?.can_edit_lines;
	useUnsavedChanges( !! ( changed || noteChanged ) );

	const problems = useMemo( () => {
		const found = [];
		if ( status === 'cancelled' || ! ( linesChanged || status !== order?.status ) ) {
			return found;
		}
		for ( const line of visibleLines ) {
			const qty = Number( line.qty );
			if ( ! line.id && ! ( qty > 0 ) ) {
				/* translators: %s: product name. */
				found.push( sprintf( __( 'Enter a quantity for "%s".', 'open-bulk-buying' ), line.product_name ) );
			} else if (
				! line.is_prepared &&
				( ! line.id || qty !== line.original_qty ) &&
				( ! Number.isInteger( qty ) || qty < 0 )
			) {
				found.push( __( 'Purchase quantities must be non-negative whole numbers of selling packages.', 'open-bulk-buying' ) );
			} else if ( ! line.is_prepared && qty > line.max_qty + EPSILON ) {
				found.push(
					sprintf(
						/* translators: 1: product name, 2: maximum quantity, 3: unit. */
						__( 'Not enough stock for "%1$s": at most %2$s × %3$s.', 'open-bulk-buying' ),
						line.product_name,
						formatQty( line.max_qty ),
						line.unit_label
					)
				);
			}
		}
		return found;
	}, [ visibleLines, status, linesChanged, order ] );

	if ( ! order ) {
		return (
			<SidePanel
				title={ __( 'Order', 'open-bulk-buying' ) }
				onClose={ onClose }
				error={ error }
				isLoading={ ! error }
			/>
		);
	}

	const subtotal = visibleLines.reduce( ( sum, line ) => sum + lineTotal( line ), 0 );
	const credit = visibleLines.reduce( ( sum, line ) => sum + lineCredit( line ), 0 );
	// Aid isn't always visible; recover it from the saved totals.
	const aid = Math.max( 0, order.subtotal - order.container_credit - ( order.total - order.donation ) );
	const total = Math.max( 0, subtotal - credit - aid ) + donation;

	const updateLine = ( key, changes ) =>
		setLines( ( prev ) =>
			prev.map( ( line ) => ( line.key === key ? { ...line, ...changes } : line ) )
		);
	const removeLine = ( line ) =>
		setLines( ( prev ) =>
			line.id
				? prev.map( ( item ) => ( item.key === line.key ? { ...item, removed: true } : item ) )
				: prev.filter( ( item ) => item.key !== line.key )
		);

	const save = async () => {
		setIsBusy( true );
		setError( null );
		const data = {
			expected_updated_at: order.updated_at || '',
		};
		if ( order.can_edit_lines ) {
			data.status = status;
			data.lines = visibleLines.map( ( line ) => ( {
				...( line.id ? { id: line.id } : { product_id: line.product_id } ),
				qty: Number( line.qty ),
				own_container: !! line.own_container,
			} ) );
		}
		if ( order.can_edit_amounts ) {
			data.donation = donation;
		}
		if ( noteChanged ) {
			data.staff_note = staffNote;
		}
		if ( needsReason ) {
			data.reason = reason;
		}
		try {
			const saved = await request( `/orders/${ order.id }`, { method: 'PUT', data } );
			load( saved );
			onSaved( saved );
		} catch ( err ) {
			setError( err );
			// Show the server's current limit on the offending product.
			if ( err.code === 'openbb_insufficient_stock' && err.data.product_id ) {
				setLines( ( prev ) =>
					prev.map( ( line ) =>
						line.product_id === err.data.product_id
							? { ...line, max_qty: err.data.max_qty }
							: line
					)
				);
			}
		}
		setIsBusy( false );
	};

	const date = ( value ) =>
		value ? dateI18n( getSettings().formats.datetime, value ) : '—';

	const confirmOrder = async () => {
		setIsBusy( true );
		setError( null );
		try {
			const saved = await request( `/orders/${ order.id }/${ confirmation }`, {
				method: 'POST',
				data: {
					confirmed: true,
					expected_updated_at: order.updated_at,
					expected_revision: order.revision,
				},
			} );
			load( saved );
			onSaved( saved );
		} catch ( err ) {
			setError( err );
		}
		setIsBusy( false );
	};

	return (
		<SidePanel
			/* translators: 1: order number, 2: member name. */
			title={ sprintf( __( 'Order #%1$d — %2$s', 'open-bulk-buying' ), order.id, order.member.name ) }
			onClose={ onClose }
		>
			<VStack spacing={ 4 } className="openbb-order-editor">
				<dl className="openbb-details openbb-details--inline">
					<dt>{ __( 'Cycle', 'open-bulk-buying' ) }</dt>
					<dd>{ order.cycle_name }</dd>
					<dt>{ __( 'Member', 'open-bulk-buying' ) }</dt>
					<dd>
						{ order.member.id && can( 'openbb_view_members' ) ? (
							<RecordLink
								type="member"
								id={ order.member.id }
								href={ `${ config.urls.members }&member=${ order.member.id }` }
							>
								{ order.member.name }
							</RecordLink>
						) : (
							order.member.name
						) }{ ' ' }
						{ order.member.email && `(${ order.member.email })` }
					</dd>
					<dt>{ __( 'Submitted', 'open-bulk-buying' ) }</dt>
					<dd>{ date( order.submitted_at ) }</dd>
					{ order.paid_at && (
						<>
							<dt>{ __( 'Payment settled', 'open-bulk-buying' ) }</dt>
							<dd>
								{ date( order.paid_at ) } ({ formatMoney( order.paid_amount ) })
							</dd>
						</>
					) }
					{ order.collected_at && (
						<>
							<dt>{ __( 'Collected', 'open-bulk-buying' ) }</dt>
							<dd>{ date( order.collected_at ) }</dd>
						</>
					) }
					{ order.aid_status_label && (
						<>
							<dt>{ __( 'Aid', 'open-bulk-buying' ) }</dt>
							<dd>
								{ order.aid_status_label }
								{ order.aid_approved > 0 && ` (${ formatMoney( order.aid_approved ) })` }
							</dd>
						</>
					) }
				</dl>

				{ order.is_locked && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This order is locked against quantity, amount, and status edits. Staff can still update its note.',
							'open-bulk-buying'
						) }
					</Notice>
				) }
				{ order.is_abandoned && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This order was not collected before its hold expired. It is abandoned and no longer reserves stock. Its payment status is retained.',
							'open-bulk-buying'
						) }
					</Notice>
				) }
				{ order.member_note && (
					<Notice status="warning" isDismissible={ false }>
						<strong>{ __( 'Member note:', 'open-bulk-buying' ) }</strong> { order.member_note }
					</Notice>
				) }
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />

				<table className="widefat openbb-lines">
					<thead>
						<tr>
							<th>{ __( 'Product', 'open-bulk-buying' ) }</th>
							<th>{ __( 'Quantity', 'open-bulk-buying' ) }</th>
							<th>{ __( 'Container', 'open-bulk-buying' ) }</th>
							<th className="openbb-lines__total">{ __( 'Total', 'open-bulk-buying' ) }</th>
							<th>
								<span className="screen-reader-text">{ __( 'Actions', 'open-bulk-buying' ) }</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ visibleLines.length === 0 && (
							<tr>
								<td colSpan={ 5 }>{ __( 'No products yet.', 'open-bulk-buying' ) }</td>
							</tr>
						) }
						{ visibleLines.map( ( line ) => (
							<LineRow
								key={ line.key }
								line={ line }
								isLocked={ isLocked }
								onChange={ ( changes ) => updateLine( line.key, changes ) }
								onRemove={ () => removeLine( line ) }
							/>
						) ) }
					</tbody>
					<tfoot>
						<tr>
							<th colSpan={ 3 }>{ __( 'Subtotal', 'open-bulk-buying' ) }</th>
							<td className="openbb-lines__total">{ formatMoney( subtotal ) }</td>
							<td />
						</tr>
						{ credit > 0 && (
							<tr>
								<th colSpan={ 3 }>{ __( 'Container credit', 'open-bulk-buying' ) }</th>
								<td className="openbb-lines__total">−{ formatMoney( credit ) }</td>
								<td />
							</tr>
						) }
						{ aid > 0 && (
							<tr>
								<th colSpan={ 3 }>{ __( 'Aid', 'open-bulk-buying' ) }</th>
								<td className="openbb-lines__total">−{ formatMoney( aid ) }</td>
								<td />
							</tr>
						) }
						{ donation > 0 && (
							<tr>
								<th colSpan={ 3 }>{ __( 'Donation', 'open-bulk-buying' ) }</th>
								<td className="openbb-lines__total">{ formatMoney( donation ) }</td>
								<td />
							</tr>
						) }
						<tr>
							<th colSpan={ 3 }>
								{ changed
									? __( 'Estimated total', 'open-bulk-buying' )
									: __( 'Total', 'open-bulk-buying' ) }
							</th>
							<td className="openbb-lines__total">
								<strong>{ formatMoney( changed ? total : order.total ) }</strong>
							</td>
							<td />
						</tr>
					</tfoot>
				</table>

				{ order.can_edit_lines && (
					<ProductPicker
						orderId={ order.id }
						excluded={ visibleLines.map( ( line ) => line.product_id ) }
						onPick={ ( product ) =>
							setLines( ( prev ) => {
								// A product keeps its one line: re-adding a removed product restores it.
								const removed = prev.find(
									( line ) => line.removed && line.product_id === product.id
								);
								return removed
									? prev.map( ( line ) =>
											line === removed
												? {
														...line,
														removed: false,
														qty: line.original_qty || 1,
												  }
												: line
									  )
									: [ ...prev, fromProduct( product ) ];
							} )
						}
					/>
				) }

				<HStack alignment="top" className="openbb-order-editor__meta">
					{ isLocked ? (
						<div>
							<strong>{ __( 'Status', 'open-bulk-buying' ) }:</strong> { order.status_label }
						</div>
					) : (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Status', 'open-bulk-buying' ) }
							value={ status }
							options={ order.editable_statuses.map( ( value ) => ( {
								value,
								label: config.orderStatuses[ value ] || value,
							} ) ) }
							onChange={ setStatus }
						/>
					) }
					{ order.can_edit_amounts && (
						<NumberControl
							__next40pxDefaultSize
							label={ __( 'Donation', 'open-bulk-buying' ) }
							min={ 0 }
							step={ 0.01 }
							value={ donationInput }
							onChange={ ( value ) => setDonationInput( value ?? '' ) }
						/>
					) }
				</HStack>

				{ canEdit && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Staff note', 'open-bulk-buying' ) }
						help={ __( 'Only visible to staff.', 'open-bulk-buying' ) }
						value={ staffNote }
						onChange={ setStaffNote }
						rows={ 3 }
					/>
				) }
				{ ! canEdit && order.staff_note && (
					<div>
						<strong>{ __( 'Staff note', 'open-bulk-buying' ) }</strong>
						<p className="openbb-pre">{ order.staff_note }</p>
					</div>
				) }

				{ needsReason && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Reason for the change (required)', 'open-bulk-buying' ) }
						help={ __( 'Added to the staff note with your name and the date.', 'open-bulk-buying' ) }
						value={ reason }
						onChange={ setReason }
						rows={ 2 }
					/>
				) }

				{ problems.length > 0 && (
					<Notice status="error" isDismissible={ false }>
						<ul className="openbb-problems">
							{ problems.map( ( problem ) => (
								<li key={ problem }>{ problem }</li>
							) ) }
						</ul>
					</Notice>
				) }

				{ ( order.can_prepay || order.can_fulfill ) && (
					<VStack spacing={ 3 }>
						<HStack justify="flex-start">
							{ order.can_prepay && (
								<Button
									variant="secondary"
									disabled={ isBusy || changed || noteChanged }
									onClick={ () => setConfirmation( 'prepay' ) }
								>
									{ __( 'Mark prepaid', 'open-bulk-buying' ) }
								</Button>
							) }
							{ order.can_fulfill && (
								<Button
									variant="secondary"
									disabled={ isBusy || changed || noteChanged }
									onClick={ () => setConfirmation( 'fulfill' ) }
								>
									{ __( 'Fulfill order', 'open-bulk-buying' ) }
								</Button>
							) }
						</HStack>
						{ ( changed || noteChanged ) && (
							<p>
								{ __(
									'Save your changes before confirming payment or fulfillment.',
									'open-bulk-buying'
								) }
							</p>
						) }
						{ confirmation && (
							<Notice status="warning" isDismissible={ false }>
								<p>
									{ confirmation === 'prepay'
										? sprintf(
												__(
													'Confirm that the full amount due (%s) is settled. Stock will remain reserved; no goods are handed over.',
													'open-bulk-buying'
												),
												order.total_formatted
										  )
										: sprintf(
												__(
													'Confirm that the full amount due (%s) is settled and the listed quantities have been handed over. This deducts stock and cannot be undone here.',
													'open-bulk-buying'
												),
												order.total_formatted
										  ) }
								</p>
								<Button
									variant="primary"
									isBusy={ isBusy }
									disabled={ isBusy || changed || noteChanged }
									onClick={ confirmOrder }
								>
									{ __( 'Confirm', 'open-bulk-buying' ) }
								</Button>
								<Button
									variant="tertiary"
									disabled={ isBusy }
									onClick={ () => setConfirmation( null ) }
								>
									{ __( 'Cancel', 'open-bulk-buying' ) }
								</Button>
							</Notice>
						) }
					</VStack>
				) }

				<HStack justify="flex-end" className="openbb-form-actions">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => {
							discardUnsavedChanges();
							onClose();
						} }
						disabled={ isBusy }
					>
						{ changed || noteChanged
							? __( 'Cancel', 'open-bulk-buying' )
							: __( 'Close', 'open-bulk-buying' ) }
					</Button>
					{ canEdit && (
						<Button
							__next40pxDefaultSize
							variant="primary"
							isBusy={ isBusy }
							disabled={
								isBusy ||
								! ( changed || noteChanged ) ||
								problems.length > 0 ||
								( needsReason && ! reason.trim() )
							}
							accessibleWhenDisabled
							onClick={ save }
						>
							{ __( 'Save order', 'open-bulk-buying' ) }
						</Button>
					) }
				</HStack>
			</VStack>
		</SidePanel>
	);
}
