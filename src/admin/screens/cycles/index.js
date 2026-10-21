import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { copy, pencil, plus, trash } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';

import { ConfirmBody, EditForm, ErrorNotice, Page, SidePanel } from '../../components';
import { useDiscardGuard, useList, useNotify } from '../../hooks';
import { Link, navigate } from '../../router';
import { request } from '../../utils/api';
import { can, config, languageLabel, toElements } from '../../utils/config';
import { formatMoney } from '../../utils/format';

const DATE_FIELDS = [ 'opens_at', 'closes_at', 'pickup_opens_at', 'pickup_closes_at' ];

const WRITABLE = [
	'name',
	'status',
	...DATE_FIELDS,
	'pickup_location',
	'pickup_instructions',
	...config.translationLanguages.map( ( lang ) => `pickup_instructions_${ lang }` ),
];

const NEW_CYCLE = {
	id: 0,
	name: '',
	status: 'setup',
	opens_at: null,
	closes_at: null,
	pickup_opens_at: null,
	pickup_closes_at: null,
	pickup_location: '',
	pickup_instructions: '',
};

const LIST_MAPPING = {
	orderby: { name: 'name', status: 'status', opens_at: 'opens_at' },
	filters: {
		status: ( filter ) => ( { status: filter.value } ),
	},
};

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'opens_at', direction: 'desc' },
	titleField: 'name',
	fields: [ 'status', 'opens_at', 'closes_at', 'pickup_opens_at', 'orders_count', 'orders_total' ],
	filters: [],
};

const dateField = ( id, label ) => ( {
	id,
	label,
	type: 'datetime',
	Edit: 'datetime',
	placeholder: __( 'Not set', 'open-bulk-buying' ),
	filterBy: false,
	enableSorting: id === 'opens_at',
} );

const cycleFields = [
	{
		id: 'name',
		label: __( 'Name', 'open-bulk-buying' ),
		type: 'text',
		isValid: { required: true },
		enableHiding: false,
		filterBy: false,
	},
	{
		id: 'status',
		label: __( 'Status', 'open-bulk-buying' ),
		type: 'text',
		Edit: 'select',
		elements: toElements( config.cycleStatuses ),
		render: ( { item } ) => (
			<span className={ `openbb-badge openbb-badge--cycle-${ item.status }` }>
				{ config.cycleStatuses[ item.status ] || item.status }
			</span>
		),
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
		description: __(
			'Members can order while the cycle is in "Ordering" and its ordering window is open.',
			'open-bulk-buying'
		),
	},
	dateField( 'opens_at', __( 'Ordering opens', 'open-bulk-buying' ) ),
	dateField( 'closes_at', __( 'Ordering closes', 'open-bulk-buying' ) ),
	dateField( 'pickup_opens_at', __( 'Pickup starts', 'open-bulk-buying' ) ),
	dateField( 'pickup_closes_at', __( 'Pickup ends', 'open-bulk-buying' ) ),
	{
		id: 'pickup_location',
		label: __( 'Pickup location', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'pickup_instructions',
		label: __( 'Pickup instructions', 'open-bulk-buying' ),
		type: 'text',
		Edit: { control: 'textarea', rows: 3 },
		enableSorting: false,
		filterBy: false,
	},
	...config.translationLanguages.map( ( lang ) => ( {
		id: `pickup_instructions_${ lang }`,
		/* translators: %s: language code. */
		label: sprintf( __( 'Pickup instructions (%s)', 'open-bulk-buying' ), languageLabel( lang ) ),
		type: 'text',
		Edit: { control: 'textarea', rows: 3 },
		enableSorting: false,
		filterBy: false,
	} ) ),
	{
		id: 'orders_count',
		label: __( 'Orders', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		render: ( { item } ) =>
			can( 'openbb_view_orders' ) ? (
				<Link href={ `${ config.urls.orders }&cycle=${ item.id }` }>
					{ item.orders_count }
				</Link>
			) : (
				item.orders_count
			),
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'submitted_count',
		label: __( 'Unfulfilled', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'prepaid_count',
		label: __( 'Prepaid', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'fulfilled_count',
		label: __( 'Fulfilled', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'orders_total',
		label: __( 'Order total', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		render: ( { item } ) => formatMoney( item.orders_total ),
		enableSorting: false,
		filterBy: false,
	},
];

/**
 * A date shown as a summary that opens the date picker in a popover, instead
 * of an always-visible calendar.
 *
 * @param {string} id
 * @param {string} label Short label within its group.
 * @return {Object} Form field.
 */
const datePicker = ( id, label ) => ( {
	id,
	label,
	layout: {
		type: 'panel',
		labelPosition: 'side',
		openAs: 'dropdown',
		editVisibility: 'always',
		showPlaceholderIfEmpty: true,
	},
} );

const cycleForm = {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [
		'name',
		'status',
		{
			id: 'ordering',
			label: __( 'Ordering window', 'open-bulk-buying' ),
			layout: { type: 'regular' },
			children: [
				datePicker( 'opens_at', __( 'Opens', 'open-bulk-buying' ) ),
				datePicker( 'closes_at', __( 'Closes', 'open-bulk-buying' ) ),
			],
		},
		{
			id: 'pickup',
			label: __( 'Pickup window', 'open-bulk-buying' ),
			layout: { type: 'regular' },
			children: [
				datePicker( 'pickup_opens_at', __( 'Starts', 'open-bulk-buying' ) ),
				datePicker( 'pickup_closes_at', __( 'Ends', 'open-bulk-buying' ) ),
			],
		},
		'pickup_location',
		'pickup_instructions',
		...config.translationLanguages.map( ( lang ) => `pickup_instructions_${ lang }` ),
	],
};

const time = ( value ) => ( value ? new Date( value ).getTime() : null );

/**
 * Mirrors the server's checks so mistakes show before saving; the server
 * re-checks (including overlaps with other cycles).
 *
 * @param {Object} data
 * @return {string|null} Error message.
 */
function validateCycle( data ) {
	const [ opens, closes ] = [ time( data.opens_at ), time( data.closes_at ) ];
	const [ pickupOpens, pickupCloses ] = [
		time( data.pickup_opens_at ),
		time( data.pickup_closes_at ),
	];
	if ( data.status !== 'setup' && ( ! opens || ! closes ) ) {
		return __( 'Set the ordering window before moving the cycle out of setup.', 'open-bulk-buying' );
	}
	if ( opens && closes && closes <= opens ) {
		return __( 'Ordering must close after it opens.', 'open-bulk-buying' );
	}
	if ( pickupOpens && pickupCloses && pickupCloses <= pickupOpens ) {
		return __( 'Pickup must end after it starts.', 'open-bulk-buying' );
	}
	return null;
}

function CycleSummary( { cycle } ) {
	return (
		<dl className="openbb-details">
			<dt>{ __( 'Orders', 'open-bulk-buying' ) }</dt>
			<dd>
				{ can( 'openbb_view_orders' ) ? (
					<Link href={ `${ config.urls.orders }&cycle=${ cycle.id }` }>
						{ cycle.orders_count }
					</Link>
				) : (
					cycle.orders_count
				) }
				{ cycle.submitted_count > 0 &&
					` (${ sprintf(
						/* translators: %d: number of committed, unfulfilled orders. */
						__( '%d unfulfilled', 'open-bulk-buying' ),
						cycle.submitted_count
					) })` }
			</dd>
			<dt>{ __( 'Prepaid', 'open-bulk-buying' ) }</dt>
			<dd>{ cycle.prepaid_count }</dd>
			<dt>{ __( 'Fulfilled', 'open-bulk-buying' ) }</dt>
			<dd>{ cycle.fulfilled_count }</dd>
			<dt>{ __( 'Order total', 'open-bulk-buying' ) }</dt>
			<dd>{ formatMoney( cycle.orders_total ) }</dd>
		</dl>
	);
}

function CyclePanel( { cycle, onClose, onSaved } ) {
	const submit = async ( data ) => {
		const payload = Object.fromEntries(
			WRITABLE.map( ( key ) => [
				key,
				DATE_FIELDS.includes( key ) ? data[ key ] || null : data[ key ] ?? '',
			] )
		);
		const saved = await request( cycle.id ? `/cycles/${ cycle.id }` : '/cycles', {
			method: cycle.id ? 'PUT' : 'POST',
			data: payload,
		} );
		onSaved( saved, ! cycle.id );
	};

	return (
		<SidePanel
			title={ cycle.id ? cycle.name : __( 'New cycle', 'open-bulk-buying' ) }
			onClose={ onClose }
		>
			<EditForm
				fields={ cycleFields }
				form={ cycleForm }
				initial={ cycle }
				validate={ validateCycle }
				requireChanges={ !! cycle.id }
				onSubmit={ submit }
				onCancel={ onClose }
				submitLabel={
					cycle.id
						? __( 'Save', 'open-bulk-buying' )
						: __( 'Create cycle', 'open-bulk-buying' )
				}
			>
				{ !! cycle.id && <CycleSummary cycle={ cycle } /> }
			</EditForm>
		</SidePanel>
	);
}

export default function CyclesScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	// { cycle, key }: the cycle being edited, or an unsaved one (id 0).
	const [ panel, setPanel ] = useState( null );
	const list = useList( '/cycles', view, LIST_MAPPING );
	const notify = useNotify();
	const canManage = can( 'openbb_manage_cycles' );
	const [ guard, discardDialog ] = useDiscardGuard();
	const panelKey = panel?.key;

	const openCycle = useCallback(
		( cycle ) => {
			if ( cycle.id && cycle.id === panelKey ) {
				return;
			}
			guard( () =>
				setPanel( { cycle, key: cycle.id || `new-${ Date.now() }` } )
			);
		},
		[ guard, panelKey ]
	);

	const actions = useMemo(
		() =>
			canManage
				? [
						{
							id: 'edit',
							label: __( 'Edit', 'open-bulk-buying' ),
							icon: pencil,
							isPrimary: true,
							callback: ( [ item ] ) => openCycle( item ),
						},
						{
							id: 'duplicate',
							label: __( 'Duplicate', 'open-bulk-buying' ),
							icon: copy,
							callback: ( [ item ] ) =>
								openCycle( {
									...NEW_CYCLE,
									/* translators: %s: cycle name. */
									name: sprintf( __( '%s (copy)', 'open-bulk-buying' ), item.name ),
									pickup_location: item.pickup_location,
									...Object.fromEntries(
										WRITABLE.filter( ( key ) =>
											key.startsWith( 'pickup_instructions' )
										).map( ( key ) => [ key, item[ key ] ] )
									),
								} ),
						},
						{
							id: 'orders',
							label: __( 'View orders', 'open-bulk-buying' ),
							isEligible: () => can( 'openbb_view_orders' ),
							callback: ( [ item ] ) => {
								navigate( `${ config.urls.orders }&cycle=${ item.id }` );
							},
						},
						{
							id: 'delete',
							label: __( 'Delete', 'open-bulk-buying' ),
							icon: trash,
							isDestructive: true,
							isEligible: ( item ) => ! item.orders_count,
							modalHeader: __( 'Delete cycle', 'open-bulk-buying' ),
							RenderModal: ( { items: [ item ], closeModal } ) => (
								<ConfirmBody
									message={ sprintf(
										/* translators: %s: cycle name. */
										__( 'Delete "%s"? This cannot be undone.', 'open-bulk-buying' ),
										item.name
									) }
									confirmLabel={ __( 'Delete', 'open-bulk-buying' ) }
									isDestructive
									closeModal={ closeModal }
									onConfirm={ async () => {
										await request( `/cycles/${ item.id }`, { method: 'DELETE' } );
										setPanel( ( current ) =>
											current?.cycle.id === item.id ? null : current
										);
										list.reload();
										notify.success( __( 'Cycle deleted.', 'open-bulk-buying' ) );
									} }
								/>
							),
						},
				  ]
				: [],
		[ canManage, list, notify, openCycle ]
	);

	return (
		<Page
			title={ __( 'Cycles', 'open-bulk-buying' ) }
			sidebar={
				panel && (
					<CyclePanel
						key={ panel.key }
						cycle={ panel.cycle }
						onClose={ () => guard( () => setPanel( null ) ) }
						onSaved={ ( item, isNew ) => {
							setPanel( { cycle: item, key: item.id } );
							if ( isNew ) {
								list.reload();
							} else {
								list.replaceItem( item );
							}
							notify.success(
								isNew
									? __( 'Cycle created.', 'open-bulk-buying' )
									: __( 'Cycle saved.', 'open-bulk-buying' )
							);
						} }
					/>
				)
			}
			actions={
				canManage && (
					<Button
						variant="primary"
						icon={ plus }
						onClick={ () => openCycle( NEW_CYCLE ) }
					>
						{ __( 'Add cycle', 'open-bulk-buying' ) }
					</Button>
				)
			}
		>
			<ErrorNotice error={ list.error } />
			{ discardDialog }
			<DataViews
				data={ list.items }
				fields={ cycleFields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ list.paginationInfo }
				isLoading={ list.isLoading }
				defaultLayouts={ { table: {}, list: {} } }
				getItemId={ ( item ) => String( item.id ) }
				onClickItem={ openCycle }
				isItemClickable={ () => canManage }
				searchLabel={ __( 'Search cycles', 'open-bulk-buying' ) }
			/>
		</Page>
	);
}
