import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pencil, plus, trash } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';

import {
	ConfirmBody,
	EditForm,
	ErrorNotice,
	Loading,
	Page,
	SidePanel,
	usePanelStack,
} from '../../components';
import { useDiscardGuard, useList, useNotify, useRecord } from '../../hooks';
import { request } from '../../utils/api';
import runForEach from '../../utils/run-for-each';
import { can, localizedRecordField, queryArg } from '../../utils/config';
import relatedPanels from '../related-panels';
import { buildProductFields, NEW_PRODUCT, productForm, WRITABLE } from './fields';
import { AdjustStockModal, InventoryPanel } from './inventory';

const pick = ( data, keys ) =>
	Object.fromEntries(
		Object.entries( data ).filter( ( [ key ] ) => keys.includes( key ) )
	);

const LIST_MAPPING = {
	orderby: { name: 'title', price: 'price', menu_order: 'menu_order' },
	filters: {
		active: ( filter ) => ( { active: filter.value ? 'active' : 'inactive' } ),
		category_ids: ( filter ) => ( { category: filter.value } ),
		supplier_id: ( filter ) => ( { supplier: filter.value } ),
	},
};

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'name', direction: 'asc' },
	titleField: 'name',
	mediaField: 'image',
	fields: [
		'brand',
		'unit_label',
		'price',
		'available_units',
		'on_hand_units',
		'reserved_units',
		'category_ids',
		'menu_order',
		'active',
	],
	filters: [],
};

function useTerms() {
	const [ terms, setTerms ] = useState( null );
	const load = useCallback( () => {
		Promise.all( [
			request( '/product-terms/categories' ),
			request( '/product-terms/suppliers' ),
		] )
			.then( ( [ categories, suppliers ] ) =>
				setTerms( { categories, suppliers } )
			)
			.catch( () => setTerms( { categories: [], suppliers: [] } ) );
	}, [] );
	useEffect( load, [ load ] );
	return [ terms, load ];
}

function ProductPanel( { productId, tab, fields, onClose, onSaved } ) {
	const { record: product, setRecord, error, isLoading } = useRecord(
		productId ? `/products/${ productId }` : null,
		NEW_PRODUCT
	);

	const update = ( item, isNew ) => {
		setRecord( item );
		onSaved( item, isNew );
	};

	const tabs = [
		{ name: 'details', title: __( 'Details', 'open-bulk-buying' ) },
		...( productId && can( 'openbb_manage_products' )
			? [ { name: 'inventory', title: __( 'Inventory', 'open-bulk-buying' ) } ]
			: [] ),
	];

	return (
		<SidePanel
			title={
				productId
					? localizedRecordField( product, 'name' ) || __( 'Product', 'open-bulk-buying' )
					: __( 'New product', 'open-bulk-buying' )
			}
			onClose={ onClose }
			error={ error }
			isLoading={ isLoading }
			tabs={ tabs }
			initialTabName={ tab }
		>
			{ ( current ) =>
				current.name === 'inventory' ? (
					<InventoryPanel
						product={ product }
						onProductChange={ ( item ) => update( item, false ) }
					/>
				) : (
					<EditForm
						fields={ fields }
						form={ productForm }
						initial={ product }
						requireChanges={ !! product.id }
						onCancel={ onClose }
						submitLabel={
							product.id
								? __( 'Save', 'open-bulk-buying' )
								: __( 'Create product', 'open-bulk-buying' )
						}
						onSubmit={ async ( data, edits ) => {
							const saved = await request(
								product.id ? `/products/${ product.id }` : '/products',
								{
									method: product.id ? 'PUT' : 'POST',
									data: pick( product.id ? edits : data, WRITABLE ),
								}
							);
							update( saved, ! product.id );
						} }
					/>
				)
			}
		</SidePanel>
	);
}

export default function ProductsTab( { nav: tabNav } ) {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ terms ] = useTerms();
	const [ panel, setPanel ] = useState( () => {
		const id = Number( queryArg( 'product' ) );
		return id ? { id, tab: 'details' } : null;
	} );
	const [ stockFor, setStockFor ] = useState( null );
	const list = useList( '/products', view, LIST_MAPPING );
	const notify = useNotify();
	const [ guard, discardDialog ] = useDiscardGuard();

	const closePanel = () =>
		guard( () => {
			setPanel( null );
			nav.reset();
		} );
	const nav = usePanelStack( {
		panels: relatedPanels,
		guard,
		base: panel?.id ? { type: 'product', id: panel.id } : null,
		onClose: closePanel,
		// An order edit changes reserved stock.
		onSaved: () => list.reload(),
	} );

	const openPanel = useCallback(
		( next ) => {
			if ( panel?.id !== next.id || panel?.tab !== next.tab || nav.top ) {
				guard( () => {
					setPanel( next );
					nav.reset();
				} );
			}
		},
		[ panel, guard, nav.top, nav.reset ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const fields = useMemo(
		() => ( terms ? buildProductFields( terms ) : [] ),
		[ terms ]
	);
	const listFields = useMemo(
		() => fields.map( ( field ) => {
			if ( field.id === 'name' ) {
				return { ...field, label: __( 'Name', 'open-bulk-buying' ) };
			}
			if ( field.id === 'description' ) {
				return { ...field, label: __( 'Description', 'open-bulk-buying' ) };
			}
			return field;
		} ),
		[ fields ]
	);

	const setActive = useCallback(
		async ( items, active ) => {
			const { done, failed } = await runForEach( items, ( item ) =>
				request( `/products/${ item.id }`, {
					method: 'PUT',
					data: { active },
				} )
			);
			done.forEach( ( { value } ) => list.replaceItem( value ) );
			if ( done.length ) {
				notify.success(
					active
						? sprintf(
								/* translators: %d: number of products. */
								_n( '%d product activated.', '%d products activated.', done.length, 'open-bulk-buying' ),
								done.length
						  )
						: sprintf(
								/* translators: %d: number of products. */
								_n( '%d product deactivated.', '%d products deactivated.', done.length, 'open-bulk-buying' ),
								done.length
						  )
				);
			}
			failed.forEach( ( { item, error } ) =>
				notify.error( `${ localizedRecordField( item, 'name' ) }: ${ error.message }` )
			);
		},
		[ list, notify ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'open-bulk-buying' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ item ] ) => openPanel( { id: item.id, tab: 'details' } ),
			},
			{
				id: 'stock',
				label: __( 'Update stock', 'open-bulk-buying' ),
				isEligible: () => can( 'openbb_manage_inventory' ),
				callback: ( [ item ] ) => setStockFor( item ),
			},
			{
				id: 'history',
				label: __( 'Stock history', 'open-bulk-buying' ),
				callback: ( [ item ] ) => openPanel( { id: item.id, tab: 'inventory' } ),
			},
			{
				id: 'activate',
				label: __( 'Activate', 'open-bulk-buying' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.active,
				callback: ( items ) => setActive( items, true ),
			},
			{
				id: 'deactivate',
				label: __( 'Deactivate', 'open-bulk-buying' ),
				supportsBulk: true,
				isEligible: ( item ) => item.active,
				callback: ( items ) => setActive( items, false ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'open-bulk-buying' ),
				icon: trash,
				isDestructive: true,
				modalHeader: __( 'Delete product', 'open-bulk-buying' ),
				RenderModal: ( { items: [ item ], closeModal } ) => (
					<ConfirmBody
						message={ sprintf(
							/* translators: %s: product name. */
							__( 'Delete "%s"? Products with orders or stock history can only be deactivated.', 'open-bulk-buying' ),
							localizedRecordField( item, 'name' )
						) }
						confirmLabel={ __( 'Delete', 'open-bulk-buying' ) }
						isDestructive
						closeModal={ closeModal }
						onConfirm={ async () => {
							await request( `/products/${ item.id }`, { method: 'DELETE' } );
							if ( panel?.id === item.id ) {
								setPanel( null );
								nav.reset();
							}
							list.reload();
							notify.success( __( 'Product deleted.', 'open-bulk-buying' ) );
						} }
					/>
				),
			},
		],
		[ list, notify, setActive, panel, openPanel, nav.reset ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	if ( ! terms ) {
		return <Loading />;
	}

	return (
		<Page
			title={ __( 'Products', 'open-bulk-buying' ) }
			actions={
				<>
					<Button
						variant="primary"
						icon={ plus }
						onClick={ () => openPanel( { id: 0, tab: 'details' } ) }
					>
						{ __( 'Add product', 'open-bulk-buying' ) }
					</Button>
				</>
			}
			sidebarSize={ nav.size || 'default' }
			sidebar={ nav.render(
				panel && (
					<ProductPanel
						key={ `${ panel.id }-${ panel.tab }` }
						productId={ panel.id }
						tab={ panel.tab }
						fields={ fields }
						onClose={ closePanel }
						onSaved={ ( item, isNew ) => {
							if ( isNew ) {
								setPanel( { id: item.id, tab: 'details' } );
								list.reload();
								notify.success( __( 'Product created.', 'open-bulk-buying' ) );
							} else {
								list.replaceItem( item );
								notify.success( __( 'Product saved.', 'open-bulk-buying' ) );
							}
						} }
					/>
				)
			) }
		>
			<ErrorNotice error={ list.error } />
			{ tabNav }
			{ discardDialog }
			<DataViews
				data={ list.items }
				fields={ listFields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ list.paginationInfo }
				isLoading={ list.isLoading }
				defaultLayouts={ { table: {}, grid: {} } }
				getItemId={ ( item ) => String( item.id ) }
				onClickItem={ ( item ) => openPanel( { id: item.id, tab: 'details' } ) }
				isItemClickable={ () => true }
				searchLabel={ __( 'Search products', 'open-bulk-buying' ) }
			/>
			{ stockFor && (
				<AdjustStockModal
					product={ stockFor }
					onClose={ () => setStockFor( null ) }
					onDone={ ( result ) => {
						setStockFor( null );
						list.replaceItem( result.product );
						notify.success( __( 'Stock updated.', 'open-bulk-buying' ) );
					} }
				/>
			) }
		</Page>
	);
}
