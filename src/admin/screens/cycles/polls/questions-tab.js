import {
	Button,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import { SortableList, moveItem } from '../../../components';
import { useNotify } from '../../../hooks';
import { request } from '../../../utils/api';
import PollNotice from './poll-notice';
import {
	MAX_QUESTIONS,
	NEW_QUESTION,
	questionLabel,
	questionTypeLabel,
} from './utils';

/**
 * The Questions tab of a poll: its questions in the order members see them.
 * Reordering, archiving and removing save at once; a question is added or
 * edited in its own panel (`onEdit`). A closed poll's questions are shown
 * read-only.
 *
 * @param {Object}   props
 * @param {Object}   props.poll
 * @param {Function} props.onChange Receives the saved poll.
 * @param {Function} props.onReload Fetches the poll again.
 * @param {Function} props.onEdit   Opens a question by key, or NEW_QUESTION.
 */
export default function QuestionsTab( { poll, onChange, onReload, onEdit } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const notify = useNotify();

	const isClosed = poll.status === 'closed';
	const isPublished = poll.status === 'published';

	const reload = async () => {
		try {
			await onReload();
			setError( null );
		} catch ( err ) {
			setError( err );
		}
	};

	// The list only changes once the save went through.
	const save = async ( questions, message ) => {
		setIsBusy( true );
		setError( null );
		try {
			onChange(
				await request( `/polls/${ poll.id }`, {
					method: 'PUT',
					data: { revision: poll.revision, questions },
				} )
			);
			if ( message ) {
				notify.success( message );
			}
		} catch ( err ) {
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	const setArchived = ( question, archived ) =>
		save(
			poll.questions.map( ( item ) =>
				item.key === question.key ? { ...item, archived } : item
			),
			archived
				? __( 'Question archived.', 'open-bulk-buying' )
				: __( 'Question restored.', 'open-bulk-buying' )
		);

	const remove = ( question ) =>
		save(
			poll.questions.filter( ( item ) => item.key !== question.key ),
			__( 'Question removed.', 'open-bulk-buying' )
		);

	return (
		<VStack spacing={ 4 } className="openbb-poll-questions">
			<PollNotice
				error={ error }
				hint={ __( 'Reload, then try again.', 'open-bulk-buying' ) }
				onReload={ reload }
				onDismiss={ () => setError( null ) }
			/>
			{ ! isClosed && (
				<HStack justify="flex-start">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						icon={ plus }
						disabled={ poll.questions.length >= MAX_QUESTIONS }
						accessibleWhenDisabled
						onClick={ () => onEdit( NEW_QUESTION ) }
					>
						{ __( 'Add question', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			) }
			{ poll.questions.length ? (
				<SortableList
					className="openbb-profile-fields"
					items={ poll.questions }
					getKey={ ( question ) => question.key }
					getLabel={ questionLabel }
					onMove={ ( from, to ) =>
						save( moveItem( poll.questions, from, to ) )
					}
					disabled={ isBusy || isClosed }
					itemClassName={ ( question ) =>
						question.archived ? 'is-archived' : ''
					}
					renderItem={ ( question ) => (
						<div className="openbb-profile-fields__item">
							<div className="openbb-profile-fields__summary">
								{ isClosed ? (
									<strong>{ questionLabel( question ) }</strong>
								) : (
									<Button
										variant="link"
										onClick={ () => onEdit( question.key ) }
									>
										{ questionLabel( question ) }
									</Button>
								) }
								<span className="openbb-profile-fields__badges">
									<span className="openbb-badge">
										{ questionTypeLabel( question ) }
									</span>
									{ question.archived && (
										<span className="openbb-badge">
											{ __(
												'Archived',
												'open-bulk-buying'
											) }
										</span>
									) }
								</span>
							</div>
							{ ! isClosed && (
								<HStack
									justify="flex-end"
									spacing={ 1 }
									expanded={ false }
									className="openbb-profile-fields__actions"
								>
									<Button
										size="compact"
										variant="tertiary"
										disabled={ isBusy }
										accessibleWhenDisabled
										onClick={ () =>
											setArchived(
												question,
												! question.archived
											)
										}
									>
										{ question.archived
											? __( 'Restore', 'open-bulk-buying' )
											: __( 'Archive', 'open-bulk-buying' ) }
									</Button>
									{ ! isPublished && (
										<Button
											size="compact"
											variant="tertiary"
											isDestructive
											disabled={ isBusy }
											accessibleWhenDisabled
											aria-label={ sprintf(
												/* translators: %s: question label. */
												__(
													'Remove %s',
													'open-bulk-buying'
												),
												questionLabel( question )
											) }
											onClick={ () => remove( question ) }
										>
											{ __( 'Remove', 'open-bulk-buying' ) }
										</Button>
									) }
								</HStack>
							) }
						</div>
					) }
				/>
			) : (
				<p>
					{ __(
						'No questions yet. Add at least one before publishing.',
						'open-bulk-buying'
					) }
				</p>
			) }
			{ ! isClosed && (
				<p className="openbb-muted">
					{ isPublished
						? __(
								'This poll is published and may have answers, so questions can be added, renamed, reordered and archived, but not removed or changed to another type. Archiving keeps the answers already given.',
								'open-bulk-buying'
						  )
						: __(
								'While the poll is a draft, questions can be changed or removed freely. No question is ever required.',
								'open-bulk-buying'
						  ) }
				</p>
			) }
		</VStack>
	);
}
