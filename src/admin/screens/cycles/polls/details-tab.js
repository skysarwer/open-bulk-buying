import {
	Button,
	Notice,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	discardUnsavedChanges,
	useNotify,
	useUnsavedChanges,
} from '../../../hooks';
import { request } from '../../../utils/api';
import { localized } from '../../../utils/config';
import PollNotice from './poll-notice';
import SettingsForm from './settings-form';
import {
	activeQuestionCount,
	audienceSummary,
	closesSummary,
	fromSettings,
	opensSummary,
	placementsSummary,
	responsesLabel,
	settingsProblems,
	toSettings,
} from './utils';

/**
 * Loads the cycles a poll's audience can be chosen from.
 *
 * @param {number} cycleId The poll's cycle, for the suggestion.
 * @return {Object|null} { suggested, cycles }, or null while loading.
 */
function useAudienceCycles( cycleId ) {
	const [ audience, setAudience ] = useState( null );

	useEffect( () => {
		let current = true;
		request( '/polls/audience-cycles', { query: { cycle_id: cycleId } } )
			.then( ( result ) => current && setAudience( result ) )
			.catch(
				() => current && setAudience( { suggested: 0, cycles: [] } )
			);
		return () => {
			current = false;
		};
	}, [ cycleId ] );

	return audience;
}

/**
 * How members reach the poll outside the order page.
 *
 * @param {Object} props
 * @param {Object} props.poll
 */
function ShareHint( { poll } ) {
	if ( poll.link ) {
		return (
			<p className="openbb-muted">
				{ __( 'Direct link:', 'open-bulk-buying' ) }{ ' ' }
				<a href={ poll.link }>{ poll.link }</a>
			</p>
		);
	}
	return (
		<p className="openbb-muted">
			{ sprintf(
				/* translators: %s: query string, e.g. ?poll=12. */
				__(
					'Direct link: add %s to the address of the page with your order form or the [openbb_polls] shortcode.',
					'open-bulk-buying'
				),
				`?poll=${ poll.id }`
			) }
		</p>
	);
}

/**
 * Where the poll stands, and the step that moves it on: publishing a draft or
 * closing a published poll. The screen asks to confirm either.
 *
 * @param {Object}   props
 * @param {Object}   props.poll
 * @param {boolean}  props.isDirty  The settings have unsaved changes.
 * @param {Function} props.onAction ( action, poll ).
 */
function PollStatus( { poll, isDirty, onAction } ) {
	const isDraft = poll.status === 'draft';
	const hasQuestions = activeQuestionCount( poll ) > 0;
	let hint = '';
	if ( isDirty ) {
		hint = isDraft
			? __(
					'Save or discard your changes before publishing.',
					'open-bulk-buying'
			  )
			: __(
					'Save or discard your changes before closing the poll.',
					'open-bulk-buying'
			  );
	} else if ( isDraft ) {
		hint = hasQuestions
			? __(
					'Members can’t see a draft. Publish it when its questions are ready.',
					'open-bulk-buying'
			  )
			: __(
					'Members can’t see a draft. Add at least one question before publishing.',
					'open-bulk-buying'
			  );
	}

	return (
		<VStack spacing={ 2 } className="openbb-poll-status">
			<HStack justify="space-between" wrap>
				<HStack justify="flex-start" spacing={ 2 } expanded={ false }>
					<span
						className={ `openbb-badge openbb-badge--poll-${ poll.phase }` }
					>
						{ poll.phase_label }
					</span>
					{ ! isDraft && (
						<span className="openbb-muted">
							{ responsesLabel( poll.responses ) }
						</span>
					) }
				</HStack>
				{ isDraft && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ isDirty || ! hasQuestions }
						accessibleWhenDisabled
						onClick={ () => onAction( 'publish', poll ) }
					>
						{ __( 'Publish', 'open-bulk-buying' ) }
					</Button>
				) }
				{ poll.status === 'published' && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ isDirty }
						accessibleWhenDisabled
						onClick={ () => onAction( 'close', poll ) }
					>
						{ __( 'Close poll', 'open-bulk-buying' ) }
					</Button>
				) }
			</HStack>
			{ hint && <p className="openbb-muted">{ hint }</p> }
			{ poll.status === 'published' && <ShareHint poll={ poll } /> }
		</VStack>
	);
}

/**
 * The Details tab of a poll: where it stands and its settings, saved with the
 * Save button. A closed poll's settings are shown read-only.
 *
 * @param {Object}   props
 * @param {Object}   props.poll
 * @param {Function} props.onChange Receives the saved poll.
 * @param {Function} props.onReload Fetches the poll again.
 * @param {Function} props.onAction ( action, poll ) to publish or close it.
 */
export default function DetailsTab( { poll, onChange, onReload, onAction } ) {
	const saved = toSettings( poll );
	// Null until edited, so the form follows the poll when it changes elsewhere.
	const [ edits, setEdits ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ showProblems, setShowProblems ] = useState( false );
	const audience = useAudienceCycles( poll.cycle_id );
	const notify = useNotify();

	const draft = edits ?? saved;
	const isClosed = poll.status === 'closed';
	const isDirty =
		! isClosed &&
		edits !== null &&
		JSON.stringify( edits ) !== JSON.stringify( saved );
	useUnsavedChanges( isDirty );
	const problems = settingsProblems( draft, poll.status === 'published' );

	const reload = async () => {
		try {
			await onReload();
			setError( null );
		} catch ( err ) {
			setError( err );
		}
	};

	const save = async ( event ) => {
		event.preventDefault();
		if ( problems.length ) {
			setShowProblems( true );
			return;
		}
		setIsBusy( true );
		setError( null );
		try {
			const next = await request( `/polls/${ poll.id }`, {
				method: 'PUT',
				data: { revision: poll.revision, ...fromSettings( draft ) },
			} );
			onChange( next );
			setEdits( null );
			setShowProblems( false );
			notify.success( __( 'Poll saved.', 'open-bulk-buying' ) );
		} catch ( err ) {
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	const status = (
		<PollStatus poll={ poll } isDirty={ isDirty } onAction={ onAction } />
	);

	if ( isClosed ) {
		return (
			<VStack spacing={ 4 }>
				{ status }
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This poll is closed. It can no longer be changed or answered; its results stay available.',
						'open-bulk-buying'
					) }
				</Notice>
				{ localized( poll.intro ) && (
					<p className="openbb-poll-panel__intro">
						{ localized( poll.intro ) }
					</p>
				) }
				<ul className="openbb-poll-panel__summary">
					<li>{ opensSummary( poll ) }</li>
					<li>{ closesSummary( poll ) }</li>
					<li>{ audienceSummary( poll, audience?.cycles ) }</li>
					<li>{ placementsSummary( poll ) }</li>
				</ul>
			</VStack>
		);
	}

	return (
		<form onSubmit={ save }>
			<VStack spacing={ 4 }>
				{ status }
				<PollNotice
					error={ error }
					hint={ __(
						'Your edits here are kept: reload, then save again.',
						'open-bulk-buying'
					) }
					onReload={ reload }
					onDismiss={ () => setError( null ) }
				/>
				{ showProblems && problems.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						<ul className="openbb-problems">
							{ problems.map( ( problem ) => (
								<li key={ problem }>{ problem }</li>
							) ) }
						</ul>
					</Notice>
				) }
				<SettingsForm
					draft={ draft }
					audience={ audience }
					missing={ poll.missing_audience_cycles }
					onChange={ ( changes ) =>
						setEdits( ( prev ) => ( {
							...( prev ?? saved ),
							...changes,
						} ) )
					}
				/>
				<HStack justify="flex-end" className="openbb-form-actions">
					{ isDirty && (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							disabled={ isBusy }
							onClick={ () => {
								discardUnsavedChanges();
								setEdits( null );
								setShowProblems( false );
							} }
						>
							{ __( 'Discard changes', 'open-bulk-buying' ) }
						</Button>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={ isBusy || ! isDirty }
						accessibleWhenDisabled
					>
						{ __( 'Save', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
}
