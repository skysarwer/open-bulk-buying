import {
	Button,
	Card,
	CardBody,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';

import { FormModal, ErrorNotice, RecordLink } from '../../components';
import { useList } from '../../hooks';
import { request, requestKey } from '../../utils/api';
import { can, config } from '../../utils/config';
import { formatQty } from '../../utils/format';

const REASON_RECEIPT = 'receipt';
const REASON_SPOILAGE = 'spoilage';
const REASON_DISTRIBUTION = 'distribution';
const REASON_ADJUSTMENT = 'adjustment';
// Entered as a positive amount, recorded as a removal.
const REMOVING_REASONS = [ REASON_SPOILAGE, REASON_DISTRIBUTION ];

/**
 * Units the stock change can be entered in: the selling unit, or any unit type
 * measuring the same thing (e.g. kg for a product sold per 500 g).
 *
 * @param {Object} product
 * @return {Array} Elements.
 */
function unitElements( product ) {
	const base = config.unitConversions[ product.unit_type ]?.[ 0 ];
	return [
		{
			value: 'selling',
			/* translators: %s: selling unit, e.g. "500 g". */
			label: sprintf( __( 'Selling units (%s)', 'open-bulk-buying' ), product.unit_label ),
		},
		...Object.entries( config.unitConversions )
			.filter( ( [ , conversion ] ) => conversion[ 0 ] === base )
			.filter( ( [ type ] ) => type !== 'unit' )
			.map( ( [ type ] ) => ( {
				value: type,
				label: config.unitTypes[ type ] || type,
			} ) ),
	];
}

const quantityLabel = ( reason ) =>
	( {
		[ REASON_RECEIPT ]: __( 'Quantity received', 'open-bulk-buying' ),
		[ REASON_SPOILAGE ]: __( 'Quantity spoiled', 'open-bulk-buying' ),
		[ REASON_DISTRIBUTION ]: __( 'Quantity distributed', 'open-bulk-buying' ),
	} )[ reason ] || __( 'Change (use a negative number to remove)', 'open-bulk-buying' );

export function AdjustStockModal( { product, onClose, onDone } ) {
	// One key per opened modal: a resubmitted request is recorded once.
	const [ key ] = useState( requestKey );

	const fields = useMemo(
		() => [
			{
				id: 'reason',
				label: __( 'Reason', 'open-bulk-buying' ),
				type: 'text',
				Edit: 'radio',
				elements: config.manualStockReasons.map( ( reason ) => ( {
					value: reason,
					label: config.stockReasons[ reason ] || reason,
				} ) ),
				isValid: { required: true },
			},
			{
				id: 'quantity',
				label: __( 'Quantity', 'open-bulk-buying' ),
				type: 'number',
				format: { decimals: 3 },
				isValid: { required: true },
			},
			{
				id: 'unit',
				label: __( 'Unit', 'open-bulk-buying' ),
				type: 'text',
				Edit: 'select',
				elements: unitElements( product ),
				isValid: { required: true },
			},
			{
				id: 'note',
				label: __( 'Note', 'open-bulk-buying' ),
				type: 'text',
				Edit: { control: 'textarea', rows: 3 },
			},
		],
		[ product ]
	);

	// One stable fields array per reason: a new array makes DataForm rebuild the
	// note's configured textarea control, which remounts it and drops focus.
	const fieldsByReason = useMemo(
		() =>
			Object.fromEntries(
				[ ...config.manualStockReasons, '' ].map( ( reason ) => [
					reason,
					fields.map( ( field ) => {
						if ( field.id === 'quantity' ) {
							return { ...field, label: quantityLabel( reason ) };
						}
						if ( field.id === 'note' && reason === REASON_ADJUSTMENT ) {
							return { ...field, label: __( 'Note (required)', 'open-bulk-buying' ) };
						}
						return field;
					} ),
				] )
			),
		[ fields ]
	);

	const validate = ( data ) => {
		const value = Number( data.quantity );
		if ( ! value ) {
			return __( 'Enter a quantity other than zero.', 'open-bulk-buying' );
		}
		if ( data.reason !== REASON_ADJUSTMENT && value < 0 ) {
			return __( 'Enter a positive quantity.', 'open-bulk-buying' );
		}
		if ( data.reason === REASON_ADJUSTMENT && ! data.note?.trim() ) {
			return __( 'Explain why the stock is being adjusted.', 'open-bulk-buying' );
		}
		return null;
	};

	const form = {
		layout: { type: 'regular', labelPosition: 'top' },
		fields: [ 'reason', 'quantity', 'unit', 'note' ],
	};

	const submit = async ( data ) => {
		const amount = Math.abs( Number( data.quantity ) );
		const quantity =
			data.reason === REASON_ADJUSTMENT
				? Number( data.quantity )
				: amount * ( REMOVING_REASONS.includes( data.reason ) ? -1 : 1 );
		const result = await request( `/products/${ product.id }/moves`, {
			method: 'POST',
			data: {
				quantity,
				unit: data.unit,
				reason: data.reason,
				note: data.note || '',
				request_key: key,
			},
		} );
		onDone( result );
	};

	return (
		<FormModal
			/* translators: %s: product name. */
			title={ sprintf( __( 'Update stock: %s', 'open-bulk-buying' ), product.name ) }
			fields={ ( data ) => fieldsByReason[ data.reason ] || fieldsByReason[ '' ] }
			form={ form }
			validate={ validate }
			initial={ { reason: REASON_RECEIPT, quantity: '', unit: 'selling', note: '' } }
			onSubmit={ submit }
			onClose={ onClose }
			submitLabel={ __( 'Record', 'open-bulk-buying' ) }
		>
			<StockSummary product={ product } />
		</FormModal>
	);
}

export function StockSummary( { product } ) {
	const cells = [
		[ __( 'On hand', 'open-bulk-buying' ), product.on_hand_units ],
		[ __( 'Reserved', 'open-bulk-buying' ), product.reserved_units ],
		[ __( 'Available', 'open-bulk-buying' ), product.available_units ],
	];
	return (
		<HStack className="openbb-stock-summary" alignment="stretch">
			{ cells.map( ( [ label, value ] ) => (
				<Card key={ label } size="small" className="openbb-stock-summary__cell">
					<CardBody>
						<div className="openbb-stock-summary__label">{ label }</div>
						<div
							className={ `openbb-stock-summary__value${
								value <= 0 ? ' is-empty' : ''
							}` }
						>
							{ formatQty( value ) }
						</div>
						<div className="openbb-stock-summary__unit">
							{ /* translators: %s: selling unit, e.g. "500 g". */ }
							{ sprintf( __( '× %s', 'open-bulk-buying' ), product.unit_label ) }
						</div>
					</CardBody>
				</Card>
			) ) }
		</HStack>
	);
}

const moveFields = [
	{
		id: 'created_at',
		label: __( 'Date', 'open-bulk-buying' ),
		type: 'datetime',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'reason_label',
		label: __( 'Reason', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'quantity_units',
		label: __( 'Change', 'open-bulk-buying' ),
		type: 'number',
		render: ( { item } ) => (
			<span
				className={
					item.quantity < 0 ? 'openbb-qty openbb-qty--minus' : 'openbb-qty'
				}
				title={ item.quantity_label }
			>
				{ item.quantity > 0 ? '+' : '' }
				{ formatQty( item.quantity_units ) } × { item.unit_label }
			</span>
		),
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'user_name',
		label: __( 'By', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'note',
		label: __( 'Note', 'open-bulk-buying' ),
		type: 'text',
		render: ( { item } ) =>
			item.order_id ? (
				<>
					<RecordLink
						type="order"
						id={ item.order_id }
						href={ `${ config.urls.orders }&order=${ item.order_id }` }
					>
						{ /* translators: %d: order number. */ }
						{ sprintf( __( 'Order #%d', 'open-bulk-buying' ), item.order_id ) }
					</RecordLink>{ ' ' }
					{ item.note }
				</>
			) : (
				item.note
			),
		enableSorting: false,
		filterBy: false,
	},
];

/**
 * Stock summary, "Update stock" and the product's ledger history.
 *
 * @param {Object} props
 */
export function InventoryPanel( { product, onProductChange } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 10,
		fields: [ 'created_at', 'reason_label', 'quantity_units', 'user_name', 'note' ],
		layout: { density: 'compact' },
	} );
	const [ isAdjusting, setIsAdjusting ] = useState( false );
	const moves = useList( `/products/${ product.id }/moves`, view );

	return (
		<VStack spacing={ 4 } className="openbb-inventory">
			<StockSummary product={ product } />
			{ can( 'openbb_manage_inventory' ) && (
				<HStack justify="flex-start">
					<Button variant="secondary" onClick={ () => setIsAdjusting( true ) }>
						{ __( 'Update stock', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			) }
			<h3 className="openbb-section-title">
				{ __( 'Stock history', 'open-bulk-buying' ) }
			</h3>
			<ErrorNotice error={ moves.error } />
			<DataViews
				data={ moves.items }
				fields={ moveFields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ moves.paginationInfo }
				isLoading={ moves.isLoading }
				defaultLayouts={ { table: {} } }
				search={ false }
				getItemId={ ( item ) => String( item.id ) }
				empty={ <p>{ __( 'No stock changes yet.', 'open-bulk-buying' ) }</p> }
			>
				<DataViews.Layout />
				<DataViews.Footer />
			</DataViews>
			{ isAdjusting && (
				<AdjustStockModal
					product={ product }
					onClose={ () => setIsAdjusting( false ) }
					onDone={ ( result ) => {
						setIsAdjusting( false );
						onProductChange( result.product );
						moves.reload();
					} }
				/>
			) }
		</VStack>
	);
}
