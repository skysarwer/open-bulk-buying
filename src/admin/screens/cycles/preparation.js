import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { ErrorNotice, Loading, SidePanel } from '../../components';
import { useNotify } from '../../hooks';
import { request } from '../../utils/api';
import { config, localizedRecordField } from '../../utils/config';
import { formatQty } from '../../utils/format';
import { AdjustStockModal, InventoryPanel } from '../products/inventory';

const methodOptions = () =>
	Object.entries( config.prepMethods || {} ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) );

function usePreparation( cycleId ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	useEffect( () => {
		let current = true;
		request( `/cycles/${ cycleId }/preparation` )
			.then( ( result ) => {
				if ( current ) {
					setData( result );
					setError( null );
				}
			} )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ cycleId, version ] );

	return {
		data,
		setData,
		error,
		reload: () => setVersion( ( v ) => v + 1 ),
	};
}

const units = ( value, item ) =>
	sprintf(
		/* translators: 1: quantity, 2: selling unit, e.g. "500 g". */
		__( '%1$s × %2$s', 'open-bulk-buying' ),
		formatQty( value ),
		item.unit_label
	);

function PreparedCell( { item, data, onChange } ) {
	const changed = item.prepared_state === 'changed';
	const name = localizedRecordField( item, 'name' );
	let help = null;
	if ( changed ) {
		help = sprintf(
			/* translators: 1: quantity set aside, 2: quantity now ordered. */
			__(
				'Orders changed: %1$s set aside, %2$s now ordered.',
				'open-bulk-buying'
			),
			formatQty( item.prepared_units ),
			formatQty( item.ordered_units )
		);
	} else if ( item.prepared_by ) {
		help = sprintf(
			/* translators: %s: person who set the product aside. */
			__( 'By %s', 'open-bulk-buying' ),
			item.prepared_by.name
		);
	}

	return (
		<>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: %s: product name. */
							__( 'Set aside %s', 'open-bulk-buying' ),
							name
						) }
					</span>
				}
				checked={ item.prepared_state === 'prepared' }
				indeterminate={ changed }
				disabled={ ! data.can_prepare }
				onChange={ ( checked ) =>
					onChange( {
						prepared: checked,
						expected_ordered: item.ordered,
					} )
				}
			/>
			{ help && (
				<div
					className={ changed ? 'openbb-prep__warning' : 'openbb-muted' }
				>
					{ help }
				</div>
			) }
		</>
	);
}

function ProductRow( { item, data, onChange, onStock, onAdjust } ) {
	const packedLocked =
		item.method === 'basket' && item.packed_lines > 0;

	return (
		<tr className={ item.is_short ? 'openbb-prep__row is-short' : 'openbb-prep__row' }>
			<td className="openbb-prep__check">
				<PreparedCell item={ item } data={ data } onChange={ onChange } />
			</td>
			<th scope="row">
				<strong>{ localizedRecordField( item, 'name' ) }</strong>
				<div className="openbb-muted">{ item.unit_label }</div>
			</th>
			<td>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Preparation', 'open-bulk-buying' ) }
					value={ item.method }
					options={ methodOptions() }
					disabled={ ! data.can_change_method || packedLocked }
					help={
						packedLocked
							? __(
									'Unpack baskets to change.',
									'open-bulk-buying'
							  )
							: undefined
					}
					onChange={ ( method ) => onChange( { method } ) }
				/>
			</td>
			<td>
				<strong>{ units( item.ordered_units, item ) }</strong>
				<div className="openbb-muted">
					{ sprintf(
						/* translators: %d: number of orders. */
						_n(
							'%d order',
							'%d orders',
							item.orders_count,
							'open-bulk-buying'
						),
						item.orders_count
					) }
					{ item.method === 'basket' &&
						' · ' +
							sprintf(
								/* translators: 1: packed order lines, 2: order lines. */
								__( '%1$d/%2$d packed', 'open-bulk-buying' ),
								item.packed_lines,
								item.lines_count
							) }
					{ item.collected_units > 0 &&
						' · ' +
							sprintf(
								/* translators: %s: quantity already handed out. */
								__( '%s handed out', 'open-bulk-buying' ),
								formatQty( item.collected_units )
							) }
				</div>
			</td>
			<td>
				{ formatQty( item.on_hand_units ) }
				{ item.is_short && (
					<div className="openbb-prep__notice">
						{ sprintf(
							/* translators: %s: missing quantity with unit. */
							__( 'Short by %s', 'open-bulk-buying' ),
							units( item.short_units, item )
						) }
					</div>
				) }
			</td>
			<td>{ formatQty( item.cycle_reserved_units ) }</td>
			<td>{ formatQty( item.other_reserved_units ) }</td>
			<td className={ item.available_units < 0 ? 'openbb-prep__negative' : '' }>
				{ formatQty( item.available_units ) }
			</td>
			<td>
				<HStack justify="flex-start" spacing={ 1 } wrap>
					{ data.can_adjust_stock && (
						<Button
							size="compact"
							variant={ item.is_short ? 'primary' : 'secondary' }
							onClick={ () => onAdjust( item ) }
						>
							{ __( 'Update stock', 'open-bulk-buying' ) }
						</Button>
					) }
					{ data.can_adjust_stock && (
						<Button
							size="compact"
							variant="tertiary"
							onClick={ () => onStock( item ) }
						>
							{ __( 'History', 'open-bulk-buying' ) }
						</Button>
					) }
				</HStack>
			</td>
		</tr>
	);
}

function Summary( { summary } ) {
	return (
		<dl className="openbb-details openbb-details--inline">
			<dt>{ __( 'Products pre-ordered', 'open-bulk-buying' ) }</dt>
			<dd>{ summary.products }</dd>
			<dt>{ __( 'Set aside', 'open-bulk-buying' ) }</dt>
			<dd>
				<strong>
					{ sprintf(
						/* translators: 1: products set aside, 2: products pre-ordered. */
						__( '%1$d of %2$d', 'open-bulk-buying' ),
						summary.prepared,
						summary.products
					) }
				</strong>
			</dd>
			{ summary.changed > 0 && (
				<>
					<dt>{ __( 'Changed since set aside', 'open-bulk-buying' ) }</dt>
					<dd className="openbb-prep__warning">{ summary.changed }</dd>
				</>
			) }
			<dt>{ __( 'Short on stock', 'open-bulk-buying' ) }</dt>
			<dd className={ summary.short > 0 ? 'openbb-prep__notice' : '' }>
				{ summary.short }
			</dd>
			<dt>{ __( 'Packed into baskets', 'open-bulk-buying' ) }</dt>
			<dd>{ summary.basket_products }</dd>
		</dl>
	);
}

/**
 * Pre-ordered products grouped by category: stock, shortages, set-aside checks and
 * how each product is prepared. Render prop receives { content, sidebar }.
 *
 * @param {Object}   props
 * @param {Object}   props.cycle
 * @param {Function} props.onBasketsChange Called with the number of basket products.
 * @param {Function} props.children
 */
export default function Preparation( { cycle, onBasketsChange, children } ) {
	const { data, setData, error, reload } = usePreparation( cycle.id );
	const [ saveError, setSaveError ] = useState( null );
	const [ stockFor, setStockFor ] = useState( null );
	const [ adjusting, setAdjusting ] = useState( null );
	const notify = useNotify();

	const update = async ( item, changes ) => {
		setSaveError( null );
		try {
			const result = await request(
				`/cycles/${ cycle.id }/preparation/${ item.id }`,
				{ method: 'POST', data: changes }
			);
			setData( result );
			onBasketsChange?.( result.summary.basket_products );
		} catch ( err ) {
			setSaveError( err );
			reload();
		}
	};

	let body = <Loading />;
	if ( data && data.sections.length === 0 ) {
		body = (
			<p>
				{ __(
					'No products have been pre-ordered in this cycle yet.',
					'open-bulk-buying'
				) }
			</p>
		);
	} else if ( data ) {
		body = (
			<table className="widefat striped openbb-prep">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Set aside', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Product', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Preparation', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Ordered', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'On hand', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Held for this cycle', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Held elsewhere', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Available', 'open-bulk-buying' ) }
						</th>
						<th scope="col">
							{ __( 'Stock', 'open-bulk-buying' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ data.sections.map( ( section ) => (
						<Fragment key={ section.id }>
							<tr className="openbb-prep__section">
								<th
									colSpan={ 9 }
									scope="colgroup"
									style={ {
										paddingInlineStart: `${
											0.75 + section.depth * 1.25
										}em`,
									} }
								>
									{ section.name }
								</th>
							</tr>
							{ section.items.map( ( item ) => (
								<ProductRow
									key={ item.id }
									item={ item }
									data={ data }
									onChange={ ( changes ) =>
										update( item, changes )
									}
									onStock={ setStockFor }
									onAdjust={ setAdjusting }
								/>
							) ) }
						</Fragment>
					) ) }
				</tbody>
			</table>
		);
	}

	const content = (
		<section aria-label={ __( 'Preparation', 'open-bulk-buying' ) }>
			<div className="openbb-cycle-workspace__intro">
				<h3>{ __( 'Preparation', 'open-bulk-buying' ) }</h3>
				{ data && <Summary summary={ data.summary } /> }
				<p className="description">
					{ __(
						'Gather each pre-ordered product from stock and tick it once it is set aside. Products packed into member baskets are ticked off per order on the Orders tab. Setting products aside does not change stock; stock leaves inventory when orders are handed out.',
						'open-bulk-buying'
					) }
				</p>
				{ data && ! data.stage_open && (
					<Notice status="info" isDismissible={ false }>
						{ data.methods_frozen
							? __(
									'Products can be set aside only while the cycle is processing or distributing.',
									'open-bulk-buying'
							  )
							: __(
									'Products can be set aside once the cycle is processing. Until then, each product follows its own preparation setting or the co-op default.',
									'open-bulk-buying'
							  ) }
					</Notice>
				) }
				<ErrorNotice error={ error } />
				<ErrorNotice
					error={ saveError }
					onDismiss={ () => setSaveError( null ) }
				/>
				<Button variant="secondary" onClick={ reload }>
					{ __( 'Refresh', 'open-bulk-buying' ) }
				</Button>
			</div>
			{ body }
			{ adjusting && (
				<AdjustStockModal
					product={ adjusting }
					onClose={ () => setAdjusting( null ) }
					onDone={ () => {
						setAdjusting( null );
						notify.success(
							__( 'Stock updated.', 'open-bulk-buying' )
						);
						reload();
					} }
				/>
			) }
		</section>
	);

	return children( {
		content,
		sidebar: stockFor && (
			<SidePanel
				key={ stockFor.id }
				title={ localizedRecordField( stockFor, 'name' ) }
				onClose={ () => setStockFor( null ) }
			>
				<InventoryPanel
					product={ stockFor }
					onProductChange={ ( product ) => {
						setStockFor( { ...stockFor, ...product } );
						reload();
					} }
				/>
			</SidePanel>
		),
	} );
}
