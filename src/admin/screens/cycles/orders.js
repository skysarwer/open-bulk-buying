import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { pencil, seen } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';

import { ErrorNotice, usePanelStack } from '../../components';
import { useDiscardGuard, useList, useNotify } from '../../hooks';
import { request } from '../../utils/api';
import { can, config } from '../../utils/config';
import relatedPanels from '../related-panels';
import OrderEditor from '../orders/editor';
import { buildFields, LIST_MAPPING } from '../orders';
import { createOrderView } from '../orders/list-view';

const PREPARATION_STAGES = [ 'processing', 'distribution' ];

function PackedToggle( { item, onPacked } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const notify = useNotify();
	if ( ! item.basket_lines ) {
		return <span className="openbb-muted">—</span>;
	}
	const packed = item.basket_packed >= item.basket_lines;
	const label = sprintf(
		/* translators: 1: packed basket products, 2: basket products in the order. */
		__( '%1$d of %2$d packed', 'open-bulk-buying' ),
		item.basket_packed,
		item.basket_lines
	);

	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ label }
			checked={ packed }
			indeterminate={ ! packed && item.basket_packed > 0 }
			disabled={ ! item.can_pack || isBusy }
			onChange={ async ( checked ) => {
				setIsBusy( true );
				try {
					onPacked(
						await request( `/orders/${ item.id }/pack`, {
							method: 'POST',
							data: {
								packed: checked,
								expected_revision: item.revision,
							},
						} )
					);
				} catch ( err ) {
					notify.error( err.message );
					onPacked( null );
				} finally {
					setIsBusy( false );
				}
			} }
		/>
	);
}

/**
 * The cycle's orders, with a basket checklist when some products are packed per order.
 * Render prop receives { content, sidebar, sidebarSize }.
 *
 * @param {Object}   props
 * @param {Object}   props.cycle
 * @param {Function} props.children
 */
export default function CycleOrders( { cycle, children } ) {
	const hasBaskets = cycle.basket_products_count > 0;
	const [ view, setView ] = useState( () => {
		const initial = createOrderView( 'cycle' );
		const fields = initial.fields.filter( ( field ) => field !== 'cycle_id' );
		return {
			...initial,
			fields: hasBaskets ? [ 'packed', ...fields ] : fields,
		};
	} );
	const [ openOrder, setOpenOrder ] = useState( null );
	const list = useList( '/orders', view, LIST_MAPPING, {
		type: 'cycle',
		cycle_id: cycle.id,
	} );
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
	const openPanel = ( id ) =>
		guard( () => {
			setOpenOrder( id );
			nav.reset();
		} );

	const fields = useMemo(
		() => [
			{
				id: 'packed',
				label: __( 'Packed', 'open-bulk-buying' ),
				type: 'text',
				render: ( { item } ) => (
					<PackedToggle
						item={ item }
						onPacked={ ( saved ) =>
							saved ? list.replaceItem( saved ) : list.reload()
						}
					/>
				),
				isVisible: () => hasBaskets,
				enableSorting: false,
				filterBy: false,
			},
			...buildFields( [ cycle ], 'cycle' ).filter(
				( field ) => field.id !== 'cycle_id'
			),
		],
		[ cycle, hasBaskets, list.replaceItem, list.reload ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const actions = [
		{
			id: 'open',
			label: canEdit
				? __( 'Edit', 'open-bulk-buying' )
				: __( 'View', 'open-bulk-buying' ),
			icon: canEdit ? pencil : seen,
			isPrimary: true,
			callback: ( [ item ] ) => openPanel( item.id ),
		},
	];

	const preparing = PREPARATION_STAGES.includes( cycle.status );
	const content = (
		<section aria-label={ __( 'Orders', 'open-bulk-buying' ) }>
			<div className="openbb-cycle-workspace__intro">
				<h3>{ __( 'Orders', 'open-bulk-buying' ) }</h3>
				{ hasBaskets && (
					<p className="description">
						{ preparing
							? __(
									'Tick an order once its basket products are packed. Products set aside by product are tracked on the Preparation tab.',
									'open-bulk-buying'
							  )
							: __(
									'Baskets can be packed once the cycle is processing.',
									'open-bulk-buying'
							  ) }
					</p>
				) }
				{ ! can( 'openbb_view_orders' ) && ! preparing && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Orders are visible to preparers while the cycle is processing or distributing.',
							'open-bulk-buying'
						) }
					</Notice>
				) }
				<Button
					variant="secondary"
					onClick={ list.reload }
					disabled={ list.isLoading }
				>
					{ __( 'Refresh orders', 'open-bulk-buying' ) }
				</Button>
			</div>
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
				searchLabel={ __(
					'Search by order number, name or email',
					'open-bulk-buying'
				) }
			/>
			{ canEdit && (
				<p>
					<a
						href={ `${ config.urls.orders }&tab=cycle&cycle=${ cycle.id }` }
					>
						{ __(
							'Add or manage orders on the Orders screen',
							'open-bulk-buying'
						) }
					</a>
				</p>
			) }
		</section>
	);

	return children( {
		content,
		sidebarSize: nav.size || ( openOrder ? 'wide' : 'default' ),
		sidebar: nav.render(
			openOrder ? (
				<OrderEditor
					key={ openOrder }
					orderId={ openOrder }
					onClose={ closePanel }
					onSaved={ ( order ) => {
						list.replaceItem( order );
						notify.success(
							__( 'Order saved.', 'open-bulk-buying' )
						);
					} }
				/>
			) : null
		),
	} );
}
