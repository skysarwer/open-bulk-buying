import {
	BaseControl,
	Button,
	SelectControl,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { can, config, localizedFieldLabel, localizedRecordField, toElements } from '../../utils/config';
import { formatMoney, formatQty, minorFactor, toMajor, toMinor } from '../../utils/format';

/** Keys the products API accepts on create/update. */
export const WRITABLE = [
	'name',
	'description',
	'brand',
	'menu_order',
	'unit_amount',
	'unit_type',
	'price',
	'container_charge',
	'active',
	'category_ids',
	'supplier_id',
	'image_id',
	...config.translationLanguages.flatMap( ( lang ) => [
		`name_${ lang }`,
		`description_${ lang }`,
	] ),
];

export const NEW_PRODUCT = {
	id: 0,
	name: '',
	description: '',
	brand: '',
	menu_order: 0,
	unit_amount: 1,
	unit_type: 'unit',
	price: 0,
	container_charge: 0,
	active: false,
	category_ids: [],
	supplier_id: 0,
	image_id: 0,
	image_url: '',
};

function ProductImage( { item } ) {
	return item.image_url ? (
		<img className="openbb-product-image" src={ item.image_url } alt="" />
	) : (
		<span className="openbb-product-image openbb-product-image--empty" />
	);
}

function ImageEdit( { data, onChange, field } ) {
	const hasMedia = can( 'upload_files' ) && window.wp?.media;

	const open = () => {
		const frame = window.wp.media( {
			title: __( 'Choose a product image', 'open-bulk-buying' ),
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Use this image', 'open-bulk-buying' ) },
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			onChange( {
				image_id: attachment.id,
				image_url:
					attachment.sizes?.thumbnail?.url ||
					attachment.sizes?.full?.url ||
					attachment.url,
			} );
		} );
		frame.open();
	};

	return (
		<BaseControl __nextHasNoMarginBottom label={ field.label } id="openbb-image">
			<HStack justify="flex-start" alignment="center">
				<ProductImage item={ data } />
				{ hasMedia && (
					<Button variant="secondary" size="compact" onClick={ open }>
						{ data.image_id
							? __( 'Replace', 'open-bulk-buying' )
							: __( 'Choose image', 'open-bulk-buying' ) }
					</Button>
				) }
				{ hasMedia && !! data.image_id && (
					<Button
						variant="tertiary"
						size="compact"
						isDestructive
						onClick={ () => onChange( { image_id: 0, image_url: '' } ) }
					>
						{ __( 'Remove', 'open-bulk-buying' ) }
					</Button>
				) }
			</HStack>
		</BaseControl>
	);
}

function stockRender( key ) {
	return function StockCell( { item } ) {
		const value = item[ key ];
		return (
			<span
				className={
					value <= 0 && key === 'available_units'
						? 'openbb-stock openbb-stock--out'
						: 'openbb-stock'
				}
			>
				{ formatQty( value ) }
			</span>
		);
	};
}

const unitsLocked = ( { item } ) => !! item.id && !! item.active;
const unitsHelp = __(
	'Deactivate the product to change its unit.',
	'open-bulk-buying'
);

const money = ( id, label ) => ( {
	id,
	label,
	type: 'number',
	getValue: ( { item } ) => toMajor( item[ id ] ),
	setValue: ( { value } ) => ( { [ id ]: toMinor( value ) } ),
	format: { decimals: Math.round( Math.log10( minorFactor() ) ) },
	render: ( { item } ) => formatMoney( item[ id ] ),
	isValid: { required: true, min: 0 },
	filterBy: false,
} );

/**
 * @param {Object} terms
 * @param {Array}  terms.categories [{ id, name }]
 * @param {Array}  terms.suppliers  [{ id, name }]
 * @return {Array} DataViews fields.
 */
export function buildProductFields( { categories, suppliers } ) {
	const categoryElements = categories.map( ( term ) => ( {
		value: term.id,
		label: term.name,
	} ) );
	const supplierElements = suppliers.map( ( term ) => ( {
		value: term.id,
		label: term.name,
	} ) );

	const translations = config.translationLanguages.flatMap( ( lang ) => [
		{
			id: `name_${ lang }`,
			label: localizedFieldLabel( __( 'Name', 'open-bulk-buying' ), lang ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
		},
		{
			id: `description_${ lang }`,
			label: localizedFieldLabel(
				__( 'Description', 'open-bulk-buying' ),
				lang
			),
			type: 'text',
			Edit: { control: 'textarea', rows: 3 },
			enableSorting: false,
			filterBy: false,
		},
	] );

	return [
		{
			id: 'image',
			label: __( 'Image', 'open-bulk-buying' ),
			type: 'media',
			getValue: ( { item } ) => item.image_url,
			render: ProductImage,
			Edit: ImageEdit,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'name',
			label: localizedFieldLabel( __( 'Name', 'open-bulk-buying' ) ),
			type: 'text',
			render: ( { item } ) => localizedRecordField( item, 'name' ),
			enableHiding: false,
			isValid: { required: true },
			filterBy: false,
		},
		{
			id: 'menu_order',
			label: __( 'Display order', 'open-bulk-buying' ),
			type: 'integer',
			isValid: { required: true },
			description: __(
				'Lower numbers appear first. Products with the same order sort by name.',
				'open-bulk-buying'
			),
			filterBy: false,
		},
		{
			id: 'brand',
			label: __( 'Brand', 'open-bulk-buying' ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'description',
			label: localizedFieldLabel( __( 'Description', 'open-bulk-buying' ) ),
			type: 'text',
			render: ( { item } ) => localizedRecordField( item, 'description' ),
			Edit: { control: 'textarea', rows: 4 },
			enableSorting: false,
			filterBy: false,
		},
		...translations,
		{
			id: 'unit_amount',
			label: __( 'Unit amount', 'open-bulk-buying' ),
			type: 'number',
			format: { decimals: 3 },
			isValid: { required: true, min: 0.001 },
			isDisabled: unitsLocked,
			description: unitsHelp,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'unit_type',
			label: __( 'Unit type', 'open-bulk-buying' ),
			type: 'text',
			Edit: 'select',
			elements: toElements( config.unitTypes ),
			isValid: { required: true },
			isDisabled: unitsLocked,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'unit_label',
			label: __( 'Sold per', 'open-bulk-buying' ),
			type: 'text',
			readOnly: true,
			enableSorting: false,
			filterBy: false,
		},
		{
			...money( 'price', __( 'Price', 'open-bulk-buying' ) ),
			description: __( 'Price per selling unit.', 'open-bulk-buying' ),
		},
		{
			...money( 'container_charge', __( 'Container charge', 'open-bulk-buying' ) ),
			enableSorting: false,
			description: __(
				'Charged per unit unless the member brings their own container.',
				'open-bulk-buying'
			),
		},
		{
			id: 'available_units',
			label: __( 'Available', 'open-bulk-buying' ),
			type: 'number',
			readOnly: true,
			render: stockRender( 'available_units' ),
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'on_hand_units',
			label: __( 'On hand', 'open-bulk-buying' ),
			type: 'number',
			readOnly: true,
			render: stockRender( 'on_hand_units' ),
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'reserved_units',
			label: __( 'Reserved', 'open-bulk-buying' ),
			type: 'number',
			readOnly: true,
			render: stockRender( 'reserved_units' ),
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'category_ids',
			label: __( 'Categories', 'open-bulk-buying' ),
			type: 'array',
			Edit: 'array',
			elements: categoryElements,
			render: ( { item } ) =>
				categoryElements
					.filter( ( el ) => item.category_ids?.includes( el.value ) )
					.map( ( el ) => el.label )
					.join( ', ' ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'supplier_id',
			label: __( 'Supplier', 'open-bulk-buying' ),
			type: 'integer',
			elements: supplierElements,
			Edit: ( { data, field, onChange } ) => (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ field.label }
					value={ String( data.supplier_id || 0 ) }
					options={ [
						{ value: '0', label: __( 'None', 'open-bulk-buying' ) },
						...supplierElements.map( ( el ) => ( {
							value: String( el.value ),
							label: el.label,
						} ) ),
					] }
					onChange={ ( value ) => onChange( { supplier_id: Number( value ) } ) }
				/>
			),
			render: ( { item } ) =>
				supplierElements.find( ( el ) => el.value === item.supplier_id )
					?.label || '',
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
		},
		{
			id: 'active',
			label: __( 'Status', 'open-bulk-buying' ),
			type: 'boolean',
			Edit: 'toggle',
			elements: [
				{ value: true, label: __( 'Active', 'open-bulk-buying' ) },
				{ value: false, label: __( 'Inactive', 'open-bulk-buying' ) },
			],
			render: ( { item } ) => (
				<span
					className={ `openbb-badge openbb-badge--${
						item.active ? 'success' : 'muted'
					}` }
				>
					{ item.active
						? __( 'Active', 'open-bulk-buying' )
						: __( 'Inactive', 'open-bulk-buying' ) }
				</span>
			),
			description: __(
				'Only active products can be ordered.',
				'open-bulk-buying'
			),
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
		},
	];
}

export const productForm = {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [
		'image',
		'name',
		'brand',
		'description',
		...config.translationLanguages.flatMap( ( lang ) => [
			`name_${ lang }`,
			`description_${ lang }`,
		] ),
		{
			id: 'unit',
			label: __( 'Unit', 'open-bulk-buying' ),
			layout: { type: 'row' },
			children: [ 'unit_amount', 'unit_type' ],
		},
		{
			id: 'pricing',
			label: __( 'Pricing', 'open-bulk-buying' ),
			layout: { type: 'row' },
			children: [ 'price', 'container_charge' ],
		},
		'category_ids',
		'supplier_id',
		'menu_order',
		'active',
	],
};
