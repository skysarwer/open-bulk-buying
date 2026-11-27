import {
	Button,
	Modal,
	SelectControl,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
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

import { ConfirmBody, ErrorNotice, Loading } from '../../../components';
import { useDiscardGuard, useNotify } from '../../../hooks';
import { request } from '../../../utils/api';
import PollPanel from './panel';
import {
	TITLE_MAX,
	activeQuestionCount,
	audienceSummary,
	closesSummary,
	opensSummary,
	placementsSummary,
	pollTitle,
	questionsLabel,
	responsesLabel,
} from './utils';

/**
 * Asks for a title and creates a draft poll.
 *
 * @param {Object}   props
 * @param {number}   props.cycleId
 * @param {Function} props.onCreated Receives the new poll.
 * @param {Function} props.onClose
 */
function CreateModal( { cycleId, onCreated, onClose } ) {
	const [ title, setTitle ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const create = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onCreated(
				await request( '/polls', {
					method: 'POST',
					data: { cycle_id: cycleId, title: { default: title } },
				} )
			);
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Add poll', 'open-bulk-buying' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ create }>
				<VStack spacing={ 4 }>
					<ErrorNotice
						error={ error }
						onDismiss={ () => setError( null ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'open-bulk-buying' ) }
						help={ __(
							'Members see this wherever the poll is shown. The poll starts as a draft: add its questions, then publish it.',
							'open-bulk-buying'
						) }
						value={ title }
						maxLength={ TITLE_MAX }
						onChange={ setTitle }
					/>
					<HStack justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ! title.trim() }
							accessibleWhenDisabled
						>
							{ __( 'Add poll', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</Modal>
	);
}

/**
 * Picks a poll of another cycle and copies it here as a new draft.
 *
 * @param {Object}   props
 * @param {number}   props.cycleId   The cycle to copy into.
 * @param {Function} props.onCreated Receives the new poll.
 * @param {Function} props.onClose
 */
function CopyModal( { cycleId, onCreated, onClose } ) {
	const [ cycles, setCycles ] = useState( null );
	const [ source, setSource ] = useState( '' );
	const [ polls, setPolls ] = useState( null );
	const [ pollId, setPollId ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	useEffect( () => {
		request( '/cycles', {
			query: { per_page: 100, orderby: 'opens_at', order: 'desc' },
		} )
			.then( ( items ) =>
				setCycles( items.filter( ( cycle ) => cycle.id !== cycleId ) )
			)
			.catch( setError );
	}, [ cycleId ] );

	useEffect( () => {
		setPolls( null );
		setPollId( '' );
		if ( ! source ) {
			return;
		}
		let current = true;
		request( '/polls', { query: { cycle_id: source } } )
			.then( ( items ) => current && setPolls( items ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ source ] );

	const copy = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onCreated(
				await request( '/polls', {
					method: 'POST',
					data: { cycle_id: cycleId, copy_from: Number( pollId ) },
				} )
			);
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Copy a poll from another cycle', 'open-bulk-buying' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ copy }>
				<VStack spacing={ 4 }>
					<ErrorNotice
						error={ error }
						onDismiss={ () => setError( null ) }
					/>
					{ ! cycles && ! error && <Loading /> }
					{ cycles && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Cycle', 'open-bulk-buying' ) }
							value={ source }
							onChange={ setSource }
							options={ [
								{
									value: '',
									label: __(
										'Choose a cycle…',
										'open-bulk-buying'
									),
								},
								...cycles.map( ( cycle ) => ( {
									value: String( cycle.id ),
									label: cycle.name,
								} ) ),
							] }
						/>
					) }
					{ source && polls && polls.length === 0 && (
						<p>
							{ __(
								'That cycle has no polls.',
								'open-bulk-buying'
							) }
						</p>
					) }
					{ source && polls && polls.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Poll', 'open-bulk-buying' ) }
							value={ pollId }
							onChange={ setPollId }
							options={ [
								{
									value: '',
									label: __(
										'Choose a poll…',
										'open-bulk-buying'
									),
								},
								...polls.map( ( poll ) => ( {
									value: String( poll.id ),
									label: pollTitle( poll ),
								} ) ),
							] }
						/>
					) }
					<p className="openbb-muted">
						{ __(
							'The copy is a separate draft: later changes to either poll don’t affect the other, and no answers are copied. Archived questions are left out, dates fall back to the default stages, and an audience of members who ordered is suggested again for this cycle. Review it before publishing.',
							'open-bulk-buying'
						) }
					</p>
					<HStack justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ! pollId }
							accessibleWhenDisabled
						>
							{ __( 'Copy poll', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</Modal>
	);
}

// What each confirmed action says and sends.
const ACTIONS = {
	publish: {
		label: __( 'Publish', 'open-bulk-buying' ),
		title: __( 'Publish poll', 'open-bulk-buying' ),
		confirmLabel: __( 'Publish', 'open-bulk-buying' ),
		message: ( poll ) =>
			sprintf(
				/* translators: 1: poll title, 2: when it opens, 3: when it closes. */
				__(
					'Publish “%1$s”? Members will be able to answer it while it is open. %2$s. %3$s. Once published, questions can be added or archived, but not removed or changed to another type.',
					'open-bulk-buying'
				),
				pollTitle( poll ),
				opensSummary( poll ),
				closesSummary( poll )
			),
		run: ( poll ) =>
			request( `/polls/${ poll.id }/status`, {
				method: 'POST',
				data: { status: 'published', revision: poll.revision },
			} ),
		done: __( 'Poll published.', 'open-bulk-buying' ),
	},
	close: {
		label: __( 'Close poll', 'open-bulk-buying' ),
		title: __( 'Close poll', 'open-bulk-buying' ),
		confirmLabel: __( 'Close poll', 'open-bulk-buying' ),
		message: ( poll ) =>
			sprintf(
				/* translators: %s: poll title. */
				__(
					'Close “%s” now? Members can no longer answer it or change their answers. This is final: a closed poll can’t be reopened or edited. Its results stay available.',
					'open-bulk-buying'
				),
				pollTitle( poll )
			),
		run: ( poll ) =>
			request( `/polls/${ poll.id }/status`, {
				method: 'POST',
				data: { status: 'closed', revision: poll.revision },
			} ),
		done: __( 'Poll closed.', 'open-bulk-buying' ),
	},
	copy: {
		label: __( 'Duplicate', 'open-bulk-buying' ),
		title: __( 'Duplicate poll', 'open-bulk-buying' ),
		confirmLabel: __( 'Duplicate', 'open-bulk-buying' ),
		message: ( poll ) =>
			sprintf(
				/* translators: %s: poll title. */
				__(
					'Add a copy of “%s” to this cycle as a new draft? The copy shares nothing with the original: no answers are copied, and later changes to either don’t affect the other.',
					'open-bulk-buying'
				),
				pollTitle( poll )
			),
		run: ( poll ) =>
			request( '/polls', {
				method: 'POST',
				data: { cycle_id: poll.cycle_id, copy_from: poll.id },
			} ),
		done: __( 'Poll copied as a draft.', 'open-bulk-buying' ),
	},
	delete: {
		label: __( 'Delete', 'open-bulk-buying' ),
		title: __( 'Delete poll', 'open-bulk-buying' ),
		confirmLabel: __( 'Delete permanently', 'open-bulk-buying' ),
		isDestructive: true,
		message: ( poll ) =>
			sprintf(
				/* translators: 1: poll title, 2: number of responses, e.g. "12 responses". */
				__(
					'Delete “%1$s” and every member’s answers to it (%2$s)? This can’t be undone.',
					'open-bulk-buying'
				),
				pollTitle( poll ),
				responsesLabel( poll.responses )
			),
		run: ( poll ) => request( `/polls/${ poll.id }`, { method: 'DELETE' } ),
		done: __( 'Poll deleted.', 'open-bulk-buying' ),
	},
};

// Lifecycle order, for the status filter.
const PHASES = [ 'draft', 'scheduled', 'open', 'ended', 'closed' ];

/**
 * The list's fields. Its status filter offers the phases of the loaded polls,
 * whose labels come from the server.
 *
 * @param {Array|null} polls
 * @return {Array} DataViews fields.
 */
function buildFields( polls ) {
	const phases = new Map(
		( polls || [] ).map( ( poll ) => [ poll.phase, poll.phase_label ] )
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
			label: __( 'Title', 'open-bulk-buying' ),
			type: 'text',
			getValue: ( { item } ) => pollTitle( item ),
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
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
					className={ `openbb-badge openbb-badge--poll-${ item.phase }` }
				>
					{ item.phase_label }
				</span>
			),
			enableSorting: false,
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
		},
		{
			id: 'responses',
			label: __( 'Responses', 'open-bulk-buying' ),
			type: 'integer',
			render: ( { item } ) => responsesLabel( item.responses ),
			filterBy: false,
		},
		{
			id: 'questions',
			label: __( 'Questions', 'open-bulk-buying' ),
			type: 'integer',
			getValue: ( { item } ) => activeQuestionCount( item ),
			render: ( { item } ) =>
				questionsLabel( activeQuestionCount( item ) ),
			filterBy: false,
		},
		summary( 'opens', __( 'Opens', 'open-bulk-buying' ), opensSummary ),
		summary( 'closes', __( 'Closes', 'open-bulk-buying' ), closesSummary ),
		summary(
			'audience',
			__( 'Who can answer', 'open-bulk-buying' ),
			audienceSummary
		),
		summary(
			'placements',
			__( 'Where it is shown', 'open-bulk-buying' ),
			placementsSummary
		),
	];
}

const INITIAL_VIEW = {
	type: 'list',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'title',
	fields: [ 'phase', 'responses', 'questions', 'opens', 'closes' ],
	filters: [],
};

/**
 * The Polls tab of a cycle: its polls in a list, and the one opened in a side
 * panel with its details, questions and results. It needs only the cycle id
 * and loads everything else itself.
 *
 * @param {Object}   props
 * @param {Object}   props.cycle
 * @param {Function} props.children ( { content, sidebar, sidebarSize } ) => Element.
 */
export default function Polls( { cycle, children } ) {
	const cycleId = cycle.id;
	const [ polls, setPolls ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( INITIAL_VIEW );
	// The open poll: { id, tab, key }. A new key remounts the panel on that tab.
	const [ panel, setPanel ] = useState( null );
	const [ modal, setModal ] = useState( null );
	// A publish or close asked for from the panel, waiting for confirmation.
	const [ pending, setPending ] = useState( null );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();
	const opened = useRef( 0 );

	const load = useCallback( async () => {
		setError( null );
		try {
			setPolls(
				await request( '/polls', { query: { cycle_id: cycleId } } )
			);
		} catch ( err ) {
			setError( err );
		}
	}, [ cycleId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const replace = useCallback(
		( saved ) =>
			setPolls( ( items ) =>
				items.map( ( item ) => ( item.id === saved.id ? saved : item ) )
			),
		[]
	);

	const open = useCallback(
		( poll, tab ) =>
			guard( () => {
				opened.current += 1;
				setPanel( { id: poll.id, tab, key: opened.current } );
			} ),
		[ guard ]
	);

	// Opening the poll that is already open keeps its panel as it is.
	const select = ( poll ) => {
		if ( panel?.id !== poll.id ) {
			open( poll, 'details' );
		}
	};

	// Response counts and phases may have moved on while the poll was open.
	const closePanel = () =>
		guard( () => {
			setPanel( null );
			load();
		} );

	const created = ( poll, tab ) => {
		setPolls( ( items ) => [ ...( items || [] ), poll ] );
		setModal( null );
		open( poll, tab );
	};

	const confirmed = useCallback(
		( action, poll, result ) => {
			if ( action === 'delete' ) {
				setPolls( ( items ) =>
					items.filter( ( item ) => item.id !== poll.id )
				);
				setPanel( ( current ) =>
					current?.id === poll.id ? null : current
				);
			} else if ( action === 'copy' ) {
				setPolls( ( items ) => [ ...items, result ] );
				open( result, 'details' );
			} else {
				replace( result );
			}
			notify.success( ACTIONS[ action ].done );
		},
		[ open, replace, notify ]
	);

	const fields = useMemo( () => buildFields( polls ), [ polls ] );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( polls ?? [], view, fields ),
		[ polls, view, fields ]
	);

	const actions = useMemo( () => {
		const confirm = ( id, isEligible ) => ( {
			id,
			label: ACTIONS[ id ].label,
			modalHeader: ACTIONS[ id ].title,
			isDestructive: ACTIONS[ id ].isDestructive,
			icon: id === 'delete' ? trash : undefined,
			isEligible,
			RenderModal: ( { items: [ poll ], closeModal } ) => (
				<ConfirmBody
					message={ ACTIONS[ id ].message( poll ) }
					confirmLabel={ ACTIONS[ id ].confirmLabel }
					isDestructive={ ACTIONS[ id ].isDestructive }
					closeModal={ closeModal }
					onConfirm={ async () =>
						confirmed( id, poll, await ACTIONS[ id ].run( poll ) )
					}
				/>
			),
		} );

		return [
			{
				id: 'edit',
				label: ( [ poll ] ) =>
					poll?.status === 'closed'
						? __( 'View', 'open-bulk-buying' )
						: __( 'Edit', 'open-bulk-buying' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ poll ] ) => open( poll, 'details' ),
			},
			{
				id: 'questions',
				label: __( 'Questions', 'open-bulk-buying' ),
				callback: ( [ poll ] ) => open( poll, 'questions' ),
			},
			{
				id: 'results',
				label: __( 'Results', 'open-bulk-buying' ),
				isEligible: ( poll ) => poll.status !== 'draft',
				callback: ( [ poll ] ) => open( poll, 'results' ),
			},
			confirm( 'publish', ( poll ) => poll.status === 'draft' ),
			confirm( 'close', ( poll ) => poll.status === 'published' ),
			confirm( 'copy' ),
			confirm( 'delete' ),
		];
	}, [ open, confirmed ] );

	const current =
		panel && polls ? polls.find( ( poll ) => poll.id === panel.id ) : null;

	const content = (
		<section
			className="openbb-polls"
			aria-label={ __( 'Polls', 'open-bulk-buying' ) }
		>
			<div className="openbb-cycle-workspace__intro">
				<h3>{ __( 'Polls', 'open-bulk-buying' ) }</h3>
				<p className="description">
					{ __(
						'Ask members questions about this cycle: a supplier vote, a preference while they order, feedback after pickup. Members answer whether or not they order, no question is required, and staff can see who answered what.',
						'open-bulk-buying'
					) }
				</p>
				<HStack justify="flex-start">
					<Button
						__next40pxDefaultSize
						variant="primary"
						icon={ plus }
						onClick={ () => setModal( 'create' ) }
					>
						{ __( 'Add poll', 'open-bulk-buying' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () => setModal( 'copy' ) }
					>
						{ __( 'Copy from another cycle…', 'open-bulk-buying' ) }
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
				isLoading={ ! polls && ! error }
				defaultLayouts={ { list: {}, table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				selection={ current ? [ String( current.id ) ] : [] }
				onChangeSelection={ ( ids ) => {
					const poll = polls?.find(
						( item ) => String( item.id ) === ids[ 0 ]
					);
					if ( poll ) {
						select( poll );
					}
				} }
				onClickItem={ select }
				isItemClickable={ () => true }
				searchLabel={ __( 'Search polls', 'open-bulk-buying' ) }
				empty={
					polls && polls.length === 0 ? (
						<p>
							{ __(
								'This cycle has no polls yet.',
								'open-bulk-buying'
							) }
						</p>
					) : undefined
				}
			/>
			{ modal === 'create' && (
				<CreateModal
					cycleId={ cycleId }
					onCreated={ ( poll ) => created( poll, 'questions' ) }
					onClose={ () => setModal( null ) }
				/>
			) }
			{ modal === 'copy' && (
				<CopyModal
					cycleId={ cycleId }
					onCreated={ ( poll ) => {
						created( poll, 'details' );
						notify.success(
							__( 'Poll copied as a draft.', 'open-bulk-buying' )
						);
					} }
					onClose={ () => setModal( null ) }
				/>
			) }
			{ pending && (
				<Modal
					title={ ACTIONS[ pending.action ].title }
					onRequestClose={ () => setPending( null ) }
					size="medium"
				>
					<ConfirmBody
						message={ ACTIONS[ pending.action ].message(
							pending.poll
						) }
						confirmLabel={ ACTIONS[ pending.action ].confirmLabel }
						isDestructive={ ACTIONS[ pending.action ].isDestructive }
						closeModal={ () => setPending( null ) }
						onConfirm={ async () =>
							confirmed(
								pending.action,
								pending.poll,
								await ACTIONS[ pending.action ].run(
									pending.poll
								)
							)
						}
					/>
				</Modal>
			) }
			{ discardDialog }
		</section>
	);

	return children( {
		content,
		sidebar: current && (
			<PollPanel
				key={ panel.key }
				poll={ current }
				tab={ panel.tab }
				onChange={ replace }
				onAction={ ( action, poll ) => setPending( { action, poll } ) }
				onClose={ closePanel }
			/>
		),
		// Room for the results table and the question editor.
		sidebarSize: 'wide',
	} );
}
