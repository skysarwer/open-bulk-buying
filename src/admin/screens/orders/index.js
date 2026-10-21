import {
	Button,
	ComboboxControl,
	SelectControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { pencil, plus, seen, trash } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';

import { ConfirmBody, ErrorNotice, Loading, Page, SidePanel, usePanelStack } from '../../components';
import {
	discardUnsavedChanges,
	useDebouncedValue,
	useDiscardGuard,
	useList,
	useNotify,
	useUnsavedChanges,
} from '../../hooks';
import { request } from '../../utils/api';
import { can, config, queryArg, toElements } from '../../utils/config';
import { formatMoney } from '../../utils/format';
import relatedPanels from '../related-panels';
import OrderEditor from './editor';

const LIST_MAPPING = {
	orderby: {
		id: 'id',
		submitted_at: 'submitted_at',
		total: 'total',
		member_name: 'member',
	},
	filters: {
		cycle_id: ( filter ) => ( { cycle_id: filter.value } ),
		status: ( filter ) => ( { status: filter.value } ),
	},
};

function useCycles() {
	const [ cycles, setCycles ] = useState( null );
	useEffect( () => {
		request( '/cycles', { query: { per_page: 100, orderby: 'opens_at', order: 'desc' } } )
			.then( setCycles )
			.catch( () => setCycles( [] ) );
	}, [] );
	return cycles;
}

function buildFields( cycles ) {
	return [
		{
			id: 'id',
			label: __( 'Order', 'open-bulk-buying' ),
			type: 'integer',
			render: ( { item } ) => `#${ item.id }`,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'member_name',
			label: __( 'Member', 'open-bulk-buying' ),
			type: 'text',
			getValue: ( { item } ) => item.member.name,
			render: ( { item } ) => (
				<>
					{ item.member.name }
					<div className="openbb-muted">{ item.member.email }</div>
				</>
			),
			filterBy: false,
		},
		{
			id: 'cycle_id',
			label: __( 'Cycle', 'open-bulk-buying' ),
			type: 'integer',
			elements: cycles.map( ( cycle ) => ( { value: cycle.id, label: cycle.name } ) ),
			render: ( { item } ) => item.cycle_name,
			filterBy: { operators: [ 'is' ], isPrimary: true },
			enableSorting: false,
		},
		{
			id: 'status',
			label: __( 'Status', 'open-bulk-buying' ),
			type: 'text',
			elements: toElements( config.orderStatuses ),
			render: ( { item } ) => (
				<>
					<span className={ `openbb-badge openbb-badge--order-${ item.status }` }>
						{ item.status_label }
					</span>
					{ item.is_abandoned && (
						<div className="openbb-muted">{ __( 'Abandoned (uncollected)', 'open-bulk-buying' ) }</div>
					) }
				</>
			),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			enableSorting: false,
		},
		{
			id: 'lines_count',
			label: __( 'Products', 'open-bulk-buying' ),
			type: 'integer',
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'total',
			label: __( 'Total', 'open-bulk-buying' ),
			type: 'integer',
			render: ( { item } ) => formatMoney( item.total ),
			filterBy: false,
		},
		{
			id: 'aid_status',
			label: __( 'Aid', 'open-bulk-buying' ),
			type: 'text',
			render: ( { item } ) => item.aid_status_label || '',
			isVisible: () => can( 'openbb_view_aid' ),
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'submitted_at',
			label: __( 'Submitted', 'open-bulk-buying' ),
			type: 'datetime',
			filterBy: false,
		},
		{
			id: 'updated_at',
			label: __( 'Updated', 'open-bulk-buying' ),
			type: 'datetime',
			enableSorting: false,
			filterBy: false,
		},
	];
}

function MemberSearch( { value, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const debounced = useDebouncedValue( search );

	useEffect( () => {
		let current = true;
		request( '/members', {
			query: {
				search: debounced,
				per_page: 20,
				coop_role: [ 'member', 'coordinator', 'manager' ],
			},
		} )
			.then( ( members ) => {
				if ( current ) {
					setOptions( ( prev ) => {
						const next = members.map( ( member ) => ( {
							value: member.id,
							label: `${ member.display_name } (${ member.email })`,
						} ) );
						const selected = prev.find( ( option ) => option.value === value );
						return selected && ! next.some( ( o ) => o.value === value )
							? [ selected, ...next ]
							: next;
					} );
				}
			} )
			.catch( () => {} );
		return () => {
			current = false;
		};
	}, [ debounced ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Member', 'open-bulk-buying' ) }
			value={ value }
			options={ options }
			onChange={ onChange }
			onFilterValueChange={ setSearch }
		/>
	);
}

function NewOrderPanel( { cycles, initialCycle, onClose, onCreated, onExisting } ) {
	const open = cycles.filter( ( cycle ) => cycle.status !== 'complete' );
	const [ cycleId, setCycleId ] = useState(
		String( open.find( ( c ) => c.id === initialCycle )?.id || open[ 0 ]?.id || '' )
	);
	const [ userId, setUserId ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useUnsavedChanges( !! userId );

	const submit = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onCreated(
				await request( '/orders', {
					method: 'POST',
					data: { cycle_id: Number( cycleId ), user_id: userId },
				} )
			);
		} catch ( err ) {
			if ( err.code === 'openbb_order_exists' && err.data.order_id ) {
				onExisting( err.data.order_id, err.message );
				return;
			}
			setError( err );
			setIsBusy( false );
		}
	};

	return (
		<SidePanel title={ __( 'New order', 'open-bulk-buying' ) } onClose={ onClose }>
			<form onSubmit={ submit } className="openbb-edit-form">
				<VStack spacing={ 4 }>
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
					{ open.length === 0 ? (
						<p>{ __( 'There are no open cycles to add an order to.', 'open-bulk-buying' ) }</p>
					) : (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Cycle', 'open-bulk-buying' ) }
							value={ cycleId }
							options={ open.map( ( cycle ) => ( {
								value: String( cycle.id ),
								label: `${ cycle.name } (${ cycle.status_label })`,
							} ) ) }
							onChange={ setCycleId }
						/>
					) }
					<MemberSearch value={ userId } onChange={ setUserId } />
					<p className="openbb-muted">
						{ __( 'The order starts as a draft. Add products, then submit it to reserve stock.', 'open-bulk-buying' ) }
					</p>
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
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ! userId || ! cycleId }
							accessibleWhenDisabled
						>
							{ __( 'Create order', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</SidePanel>
	);
}

export default function OrdersScreen() {
	const initialCycle = Number( queryArg( 'cycle' ) ) || 0;
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		sort: { field: 'id', direction: 'desc' },
		titleField: 'member_name',
		fields: [ 'id', 'cycle_id', 'status', 'lines_count', 'total', 'aid_status', 'submitted_at' ],
		filters: initialCycle ? [ { field: 'cycle_id', operator: 'is', value: initialCycle } ] : [],
	} );
	// An order id, or 0 to start a new order.
	const [ openOrder, setOpenOrder ] = useState( () => Number( queryArg( 'order' ) ) || null );
	const cycles = useCycles();
	const list = useList( '/orders', view, LIST_MAPPING );
	const notify = useNotify();
	const canEdit = can( 'openbb_edit_orders' );
	const [ guard, discardDialog ] = useDiscardGuard();

	const closePanel = () =>
		guard( () => {
			setOpenOrder( null );
			nav.reset();
		} );
	const nav = usePanelStack( {
		panels: relatedPanels,
		guard,
		base: openOrder ? { type: 'order', id: openOrder } : null,
		onClose: closePanel,
		onSaved: ( entry, item ) => {
			if ( entry.type === 'order' ) {
				list.replaceItem( item );
			}
		},
	} );

	const openPanel = useCallback(
		( id ) => {
			if ( id !== openOrder || nav.top ) {
				guard( () => {
					setOpenOrder( id );
					nav.reset();
				} );
			}
		},
		[ openOrder, guard, nav.top, nav.reset ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const fields = useMemo( () => ( cycles ? buildFields( cycles ) : [] ), [ cycles ] );

	const actions = useMemo(
		() => [
			{
				id: 'open',
				label: canEdit ? __( 'Edit', 'open-bulk-buying' ) : __( 'View', 'open-bulk-buying' ),
				icon: canEdit ? pencil : seen,
				isPrimary: true,
				callback: ( [ item ] ) => openPanel( item.id ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'open-bulk-buying' ),
				icon: trash,
				isDestructive: true,
				isEligible: ( item ) => item.can_delete,
				modalHeader: __( 'Delete order', 'open-bulk-buying' ),
				RenderModal: ( { items: [ item ], closeModal } ) => (
					<ConfirmBody
						message={ sprintf(
							/* translators: 1: order number, 2: member name. */
							__( 'Delete order #%1$d for %2$s? Consider cancelling it instead to keep its history.', 'open-bulk-buying' ),
							item.id,
							item.member.name
						) }
						confirmLabel={ __( 'Delete', 'open-bulk-buying' ) }
						isDestructive
						closeModal={ closeModal }
						onConfirm={ async () => {
							await request( `/orders/${ item.id }`, { method: 'DELETE' } );
							if ( item.id === openOrder ) {
								setOpenOrder( null );
								nav.reset();
							}
							list.reload();
							notify.success( __( 'Order deleted.', 'open-bulk-buying' ) );
						} }
					/>
				),
			},
		],
		[ canEdit, list, notify, openPanel, openOrder, nav.reset ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	if ( ! cycles ) {
		return <Loading />;
	}

	let sidebar = null;
	if ( openOrder === 0 ) {
		sidebar = (
			<NewOrderPanel
				cycles={ cycles }
				initialCycle={ view.filters.find( ( filter ) => filter.field === 'cycle_id' )?.value }
				onClose={ closePanel }
				onCreated={ ( order ) => {
					setOpenOrder( order.id );
					list.reload();
				} }
				onExisting={ ( orderId, message ) => {
					setOpenOrder( orderId );
					notify.error( message );
				} }
			/>
		);
	} else if ( openOrder ) {
		sidebar = (
			<OrderEditor
				key={ openOrder }
				orderId={ openOrder }
				onClose={ closePanel }
				onSaved={ ( order ) => {
					list.replaceItem( order );
					notify.success( __( 'Order saved.', 'open-bulk-buying' ) );
				} }
			/>
		);
	}

	return (
		<Page
			title={ __( 'Orders', 'open-bulk-buying' ) }
			sidebarSize={ nav.size || ( openOrder ? 'wide' : 'default' ) }
			sidebar={ nav.render( sidebar ) }
			actions={
				canEdit && (
					<Button variant="primary" icon={ plus } onClick={ () => openPanel( 0 ) }>
						{ __( 'Add order', 'open-bulk-buying' ) }
					</Button>
				)
			}
		>
			<ErrorNotice error={ list.error } />
			{ discardDialog }
			<DataViews
				data={ list.items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ list.paginationInfo }
				isLoading={ list.isLoading }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				onClickItem={ ( item ) => openPanel( item.id ) }
				isItemClickable={ () => true }
				searchLabel={ __( 'Search by order number, name or email', 'open-bulk-buying' ) }
			/>
		</Page>
	);
}
