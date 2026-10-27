import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pencil, plus, trash } from '@wordpress/icons';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';

import {
	ConfirmBody,
	EditForm,
	ErrorNotice,
	Loading,
	Page,
	SidePanel,
} from '../../components';
import { useDiscardGuard, useNotify } from '../../hooks';
import { request } from '../../utils/api';
import {
	config,
	localizedFieldLabel,
	localizedRecordField,
} from '../../utils/config';

const translationKeys = config.translationLanguages.map(
	( lang ) => `name_${ lang }`
);

const countLabel = ( count ) =>
	sprintf(
		/* translators: %d: number of products. */
		_n( '%d product', '%d products', count, 'open-bulk-buying' ),
		count
	);

const STRINGS = {
	categories: {
		add: __( 'Add category', 'open-bulk-buying' ),
		newTitle: __( 'New category', 'open-bulk-buying' ),
		fallbackTitle: __( 'Category', 'open-bulk-buying' ),
		create: __( 'Create category', 'open-bulk-buying' ),
		created: __( 'Category created.', 'open-bulk-buying' ),
		saved: __( 'Category saved.', 'open-bulk-buying' ),
		deleted: __( 'Category deleted.', 'open-bulk-buying' ),
		deleteHeader: __( 'Delete category', 'open-bulk-buying' ),
		search: __( 'Search categories', 'open-bulk-buying' ),
		/* translators: %s: category name. */
		deleteMessage: __(
			'Delete "%s"? Its products stay, but lose this category, and its sub-categories move up a level.',
			'open-bulk-buying'
		),
	},
	suppliers: {
		add: __( 'Add supplier', 'open-bulk-buying' ),
		newTitle: __( 'New supplier', 'open-bulk-buying' ),
		fallbackTitle: __( 'Supplier', 'open-bulk-buying' ),
		create: __( 'Create supplier', 'open-bulk-buying' ),
		created: __( 'Supplier created.', 'open-bulk-buying' ),
		saved: __( 'Supplier saved.', 'open-bulk-buying' ),
		deleted: __( 'Supplier deleted.', 'open-bulk-buying' ),
		deleteHeader: __( 'Delete supplier', 'open-bulk-buying' ),
		search: __( 'Search suppliers', 'open-bulk-buying' ),
		/* translators: %s: supplier name. */
		deleteMessage: __(
			'Delete "%s"? Its products stay, but lose this supplier.',
			'open-bulk-buying'
		),
	},
};

function buildFields( isCategory ) {
	return [
		{
			id: 'name',
			label: localizedFieldLabel( __( 'Name', 'open-bulk-buying' ) ),
			type: 'text',
			render: ( { item } ) => localizedRecordField( item, 'name' ),
			enableHiding: false,
			isValid: { required: true },
			filterBy: false,
		},
		...config.translationLanguages.map( ( lang ) => ( {
			id: `name_${ lang }`,
			label: localizedFieldLabel( __( 'Name', 'open-bulk-buying' ), lang ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
		} ) ),
		...( isCategory
			? [
					{
						id: 'order',
						label: __( 'Display order', 'open-bulk-buying' ),
						type: 'integer',
						isValid: { required: true },
						description: __(
							'Lower numbers appear first. Categories with the same order sort by name.',
							'open-bulk-buying'
						),
						filterBy: false,
					},
			  ]
			: [] ),
		{
			id: 'count',
			label: __( 'Products', 'open-bulk-buying' ),
			type: 'integer',
			readOnly: true,
			render: ( { item } ) => countLabel( item.count ),
			filterBy: false,
		},
	];
}

const buildForm = ( isCategory ) => ( {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [
		'name',
		...translationKeys,
		...( isCategory ? [ 'order' ] : [] ),
	],
} );

const newTerm = ( isCategory ) => ( {
	id: 0,
	name: '',
	...Object.fromEntries( translationKeys.map( ( key ) => [ key, '' ] ) ),
	...( isCategory ? { order: 0 } : {} ),
} );

function TermPanel( { taxonomy, term, fields, form, onClose, onSaved } ) {
	const isCategory = taxonomy === 'categories';
	const strings = STRINGS[ taxonomy ];
	const writable = [
		'name',
		...translationKeys,
		...( isCategory ? [ 'order' ] : [] ),
	];

	return (
		<SidePanel
			title={
				term.id
					? localizedRecordField( term, 'name' ) || strings.fallbackTitle
					: strings.newTitle
			}
			onClose={ onClose }
		>
			<EditForm
				fields={ fields }
				form={ form }
				initial={ term }
				requireChanges={ !! term.id }
				onCancel={ onClose }
				submitLabel={ term.id ? __( 'Save', 'open-bulk-buying' ) : strings.create }
				onSubmit={ async ( data, edits ) => {
					const source = term.id ? edits : data;
					const saved = await request(
						term.id
							? `/product-terms/${ taxonomy }/${ term.id }`
							: `/product-terms/${ taxonomy }`,
						{
							method: term.id ? 'PUT' : 'POST',
							data: Object.fromEntries(
								Object.entries( source ).filter( ( [ key ] ) =>
									writable.includes( key )
								)
							),
						}
					);
					onSaved( saved, ! term.id );
				} }
			/>
		</SidePanel>
	);
}

/**
 * A list of product suppliers or categories, edited in a side panel.
 *
 * @param {Object}  props
 * @param {string}  props.taxonomy 'suppliers' or 'categories'.
 * @param {Element} props.nav      The tab links, from the Products screen.
 */
export default function TermsTab( { taxonomy, nav } ) {
	const isCategory = taxonomy === 'categories';
	const strings = STRINGS[ taxonomy ];
	const [ terms, setTerms ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ panel, setPanel ] = useState( null );
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		sort: { field: isCategory ? 'order' : 'name', direction: 'asc' },
		titleField: 'name',
		fields: isCategory ? [ 'order', 'count' ] : [ 'count' ],
		filters: [],
	} );
	const notify = useNotify();
	const [ guard, discardDialog ] = useDiscardGuard();

	const load = useCallback( () => {
		request( `/product-terms/${ taxonomy }` )
			.then( ( items ) => {
				setError( null );
				setTerms( items );
			} )
			.catch( ( err ) => {
				setError( err );
				setTerms( ( current ) => current ?? [] );
			} );
	}, [ taxonomy ] );
	useEffect( load, [ load ] );

	const fields = useMemo( () => buildFields( isCategory ), [ isCategory ] );
	const form = useMemo( () => buildForm( isCategory ), [ isCategory ] );

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( terms ?? [], view, fields ),
		[ terms, view, fields ]
	);

	const openPanel = ( next ) => {
		if ( panel?.id !== next.id ) {
			guard( () => setPanel( next ) );
		}
	};
	const closePanel = () => guard( () => setPanel( null ) );

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'open-bulk-buying' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ item ] ) => openPanel( item ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'open-bulk-buying' ),
				icon: trash,
				isDestructive: true,
				modalHeader: strings.deleteHeader,
				RenderModal: ( { items: [ item ], closeModal } ) => (
					<ConfirmBody
						message={ sprintf(
							strings.deleteMessage,
							localizedRecordField( item, 'name' )
						) }
						confirmLabel={ __( 'Delete', 'open-bulk-buying' ) }
						isDestructive
						closeModal={ closeModal }
						onConfirm={ async () => {
							await request(
								`/product-terms/${ taxonomy }/${ item.id }`,
								{ method: 'DELETE' }
							);
							if ( panel?.id === item.id ) {
								setPanel( null );
							}
							load();
							notify.success( strings.deleted );
						} }
					/>
				),
			},
		],
		[ panel, taxonomy, strings, load, notify ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	return (
		<Page
			title={ __( 'Products', 'open-bulk-buying' ) }
			actions={
				<Button
					variant="primary"
					icon={ plus }
					onClick={ () => openPanel( newTerm( isCategory ) ) }
				>
					{ strings.add }
				</Button>
			}
			sidebar={
				panel && (
					<TermPanel
						key={ panel.id }
						taxonomy={ taxonomy }
						term={ panel }
						fields={ fields }
						form={ form }
						onClose={ closePanel }
						onSaved={ ( saved, isNew ) => {
							// Categories re-sort by order, so refresh the list either way.
							load();
							setPanel( saved );
							notify.success( isNew ? strings.created : strings.saved );
						} }
					/>
				)
			}
		>
			{ nav }
			<ErrorNotice error={ error } />
			{ discardDialog }
			{ ! terms ? (
				<Loading />
			) : (
				<DataViews
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => String( item.id ) }
					onClickItem={ ( item ) => openPanel( item ) }
					isItemClickable={ () => true }
					searchLabel={ strings.search }
				/>
			) }
		</Page>
	);
}
