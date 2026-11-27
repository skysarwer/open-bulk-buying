import {
	Button,
	Modal,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { pencil, plus, trash } from '@wordpress/icons';

import { ConfirmBody, ErrorNotice } from '../../../components';
import { useDiscardGuard, useNotify } from '../../../hooks';
import { request } from '../../../utils/api';
import { localized, toElements } from '../../../utils/config';
import NoticePanel from './panel';
import {
	PHASES,
	TYPE_LABELS,
	closesSummary,
	hasCta,
	noticeTitle,
	opensSummary,
	typeLabel,
} from './utils';

const endpoint = ( notice ) =>
	`/cycles/${ notice.cycle_id }/notices/${ notice.id }`;

const setStatus = ( notice, status ) =>
	request( endpoint( notice ), {
		method: 'PUT',
		data: { status, revision: notice.revision },
	} );

// What each confirmed action says and sends.
const ACTIONS = {
	publish: {
		label: __( 'Publish', 'open-bulk-buying' ),
		title: __( 'Publish notice', 'open-bulk-buying' ),
		confirmLabel: __( 'Publish', 'open-bulk-buying' ),
		message: ( notice ) =>
			sprintf(
				/* translators: 1: notice text, 2: when it is shown, 3: when it is hidden. */
				__(
					'Publish “%1$s”? Members will see it on the order page. %2$s. %3$s.',
					'open-bulk-buying'
				),
				noticeTitle( notice ),
				opensSummary( notice ),
				closesSummary( notice )
			),
		run: ( notice ) => setStatus( notice, 'published' ),
		done: __( 'Notice published.', 'open-bulk-buying' ),
	},
	unpublish: {
		label: __( 'Unpublish', 'open-bulk-buying' ),
		title: __( 'Unpublish notice', 'open-bulk-buying' ),
		confirmLabel: __( 'Unpublish', 'open-bulk-buying' ),
		message: ( notice ) =>
			sprintf(
				/* translators: %s: notice text. */
				__(
					'Unpublish “%s”? It is hidden from members straight away and becomes a draft, which you can edit and publish again.',
					'open-bulk-buying'
				),
				noticeTitle( notice )
			),
		run: ( notice ) => setStatus( notice, 'draft' ),
		done: __( 'Notice unpublished.', 'open-bulk-buying' ),
	},
	delete: {
		label: __( 'Delete', 'open-bulk-buying' ),
		title: __( 'Delete notice', 'open-bulk-buying' ),
		confirmLabel: __( 'Delete permanently', 'open-bulk-buying' ),
		isDestructive: true,
		message: ( notice ) =>
			sprintf(
				/* translators: %s: notice text. */
				__(
					'Delete “%s”? Members no longer see it. This can’t be undone.',
					'open-bulk-buying'
				),
				noticeTitle( notice )
			),
		run: ( notice ) => request( endpoint( notice ), { method: 'DELETE' } ),
		done: __( 'Notice deleted.', 'open-bulk-buying' ),
	},
};

/**
 * The list's fields. Its status filter offers the phases of the loaded
 * notices, whose labels come from the server.
 *
 * @param {Array|null} notices
 * @return {Array} DataViews fields.
 */
function buildFields( notices ) {
	const phases = new Map(
		( notices || [] ).map( ( notice ) => [
			notice.phase,
			notice.phase_label,
		] )
	);
	const summary = ( id, label, getValue ) => ( {
		id,
		label,
		type: 'text',
		getValue: ( { item } ) => getValue( item ),
		enableSorting: false,
		filterBy: false,
	} );

	return [
		{
			id: 'title',
			label: __( 'Notice', 'open-bulk-buying' ),
			type: 'text',
			// Searches the whole text; the list shows it cut short.
			getValue: ( { item } ) => localized( item.content ),
			render: ( { item } ) => noticeTitle( item ),
			enableGlobalSearch: true,
			enableHiding: false,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'type',
			label: __( 'Kind', 'open-bulk-buying' ),
			type: 'text',
			elements: toElements( TYPE_LABELS ),
			render: ( { item } ) => typeLabel( item ),
			enableSorting: false,
			filterBy: { operators: [ 'isAny' ] },
		},
		{
			id: 'phase',
			label: __( 'Status', 'open-bulk-buying' ),
			type: 'text',
			elements: PHASES.filter( ( phase ) => phases.has( phase ) ).map(
				( phase ) => ( { value: phase, label: phases.get( phase ) } )
			),
			render: ( { item } ) => (
				<span
					className={ `openbb-badge openbb-badge--notice-${ item.phase }` }
				>
					{ item.phase_label }
				</span>
			),
			enableSorting: false,
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
		},
		summary( 'opens', __( 'Shown', 'open-bulk-buying' ), opensSummary ),
		summary( 'closes', __( 'Hidden', 'open-bulk-buying' ), closesSummary ),
		summary( 'cta', __( 'Button', 'open-bulk-buying' ), ( notice ) =>
			hasCta( notice ) ? localized( notice.cta_text ) : ''
		),
	];
}

const INITIAL_VIEW = {
	type: 'list',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'title',
	fields: [ 'type', 'phase', 'opens', 'closes', 'cta' ],
	filters: [],
};

/**
 * The Notices tab of a cycle: its notices in a list, and the one opened (or
 * being added) in a side panel. It needs only the cycle and loads everything
 * else itself.
 *
 * @param {Object}   props
 * @param {Object}   props.cycle
 * @param {Function} props.children ( { content, sidebar, sidebarSize } ) => Element.
 */
export default function Notices( { cycle, children } ) {
	const cycleId = cycle.id;
	const [ notices, setNotices ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( INITIAL_VIEW );
	// The open panel: { id, key }. A null id is a notice being added.
	const [ panel, setPanel ] = useState( null );
	// A publish, unpublish or delete asked for from the panel, waiting for confirmation.
	const [ pending, setPending ] = useState( null );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();
	const opened = useRef( 0 );

	const load = useCallback( async () => {
		setError( null );
		try {
			setNotices( await request( `/cycles/${ cycleId }/notices` ) );
		} catch ( err ) {
			setError( err );
		}
	}, [ cycleId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const open = useCallback(
		( id ) =>
			guard( () => {
				opened.current += 1;
				setPanel( { id, key: opened.current } );
			} ),
		[ guard ]
	);

	// Opening the notice that is already open keeps its panel as it is.
	const select = ( notice ) => {
		if ( panel?.id !== notice.id ) {
			open( notice.id );
		}
	};

	// Phases may have moved on with the cycle while the panel was open.
	const closePanel = () =>
		guard( () => {
			setPanel( null );
			load();
		} );

	// Saving a new notice turns its panel into the saved notice's, without remounting it.
	const saved = useCallback( ( notice ) => {
		setNotices( ( items ) =>
			items.some( ( item ) => item.id === notice.id )
				? items.map( ( item ) =>
						item.id === notice.id ? notice : item
				  )
				: [ ...items, notice ]
		);
		setPanel( ( current ) =>
			current && current.id === null
				? { ...current, id: notice.id }
				: current
		);
	}, [] );

	const confirmed = useCallback(
		( action, notice, result ) => {
			if ( action === 'delete' ) {
				setNotices( ( items ) =>
					items.filter( ( item ) => item.id !== notice.id )
				);
				setPanel( ( current ) =>
					current?.id === notice.id ? null : current
				);
			} else {
				saved( result );
			}
			notify.success( ACTIONS[ action ].done );
		},
		[ saved, notify ]
	);

	const fields = useMemo( () => buildFields( notices ), [ notices ] );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( notices ?? [], view, fields ),
		[ notices, view, fields ]
	);

	const actions = useMemo( () => {
		const confirm = ( id, isEligible ) => ( {
			id,
			label: ACTIONS[ id ].label,
			modalHeader: ACTIONS[ id ].title,
			isDestructive: ACTIONS[ id ].isDestructive,
			icon: id === 'delete' ? trash : undefined,
			isEligible,
			RenderModal: ( { items: [ notice ], closeModal } ) => (
				<ConfirmBody
					message={ ACTIONS[ id ].message( notice ) }
					confirmLabel={ ACTIONS[ id ].confirmLabel }
					isDestructive={ ACTIONS[ id ].isDestructive }
					closeModal={ closeModal }
					onConfirm={ async () =>
						confirmed(
							id,
							notice,
							await ACTIONS[ id ].run( notice )
						)
					}
				/>
			),
		} );

		return [
			{
				id: 'edit',
				label: __( 'Edit', 'open-bulk-buying' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ notice ] ) => open( notice.id ),
			},
			confirm( 'publish', ( notice ) => notice.status === 'draft' ),
			confirm( 'unpublish', ( notice ) => notice.status === 'published' ),
			confirm( 'delete' ),
		];
	}, [ open, confirmed ] );

	const current =
		panel?.id && notices
			? notices.find( ( notice ) => notice.id === panel.id )
			: null;

	const content = (
		<section
			className="openbb-notices"
			aria-label={ __( 'Notices', 'open-bulk-buying' ) }
		>
			<div className="openbb-cycle-workspace__intro">
				<h3>{ __( 'Notices', 'open-bulk-buying' ) }</h3>
				<p className="description">
					{ __(
						'Short messages for members about this cycle: a pickup change, a deadline reminder, a supplier delay. Members see published notices on the order page, and while ordering is closed. Use an alert for what they should not miss, and a button to point them to more details.',
						'open-bulk-buying'
					) }
				</p>
				<HStack justify="flex-start">
					<Button
						__next40pxDefaultSize
						variant="primary"
						icon={ plus }
						onClick={ () => open( null ) }
					>
						{ __( 'Add notice', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			</div>
			<ErrorNotice error={ error } />
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ ! notices && ! error }
				defaultLayouts={ { list: {}, table: {} } }
				getItemId={ ( item ) => item.id }
				selection={ current ? [ current.id ] : [] }
				onChangeSelection={ ( ids ) => {
					const notice = notices?.find(
						( item ) => item.id === ids[ 0 ]
					);
					if ( notice ) {
						select( notice );
					}
				} }
				onClickItem={ select }
				isItemClickable={ () => true }
				searchLabel={ __( 'Search notices', 'open-bulk-buying' ) }
				empty={
					notices && notices.length === 0 ? (
						<p>
							{ __(
								'This cycle has no notices yet.',
								'open-bulk-buying'
							) }
						</p>
					) : undefined
				}
			/>
			{ pending && (
				<Modal
					title={ ACTIONS[ pending.action ].title }
					onRequestClose={ () => setPending( null ) }
					size="medium"
				>
					<ConfirmBody
						message={ ACTIONS[ pending.action ].message(
							pending.notice
						) }
						confirmLabel={ ACTIONS[ pending.action ].confirmLabel }
						isDestructive={
							ACTIONS[ pending.action ].isDestructive
						}
						closeModal={ () => setPending( null ) }
						onConfirm={ async () =>
							confirmed(
								pending.action,
								pending.notice,
								await ACTIONS[ pending.action ].run(
									pending.notice
								)
							)
						}
					/>
				</Modal>
			) }
			{ discardDialog }
		</section>
	);

	let sidebar = null;
	if ( panel && ( panel.id === null || current ) ) {
		sidebar = (
			<NoticePanel
				key={ panel.key }
				cycleId={ cycleId }
				notice={ current }
				onSaved={ saved }
				onAction={ ( action, notice ) =>
					setPending( { action, notice } )
				}
				onClose={ closePanel }
			/>
		);
	}

	return children( { content, sidebar } );
}
