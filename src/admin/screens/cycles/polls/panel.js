import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SidePanel } from '../../../components';
import { FieldPanel } from '../../../components/field-editor';
import { useDiscardGuard, useNotify } from '../../../hooks';
import { request } from '../../../utils/api';
import DetailsTab from './details-tab';
import QuestionsTab from './questions-tab';
import PollResults from './results';
import QUESTION_CONFIG from './question-config';
import { NEW_QUESTION, pollTitle } from './utils';

/**
 * The side panel of one poll, in tabs: its details, its questions and, once
 * published, its results. Opening a question shows its editor in place of the
 * panel, with a back button to the questions.
 *
 * Every save carries the poll's revision; one based on an outdated copy is
 * refused and can be redone after reloading.
 *
 * @param {Object}   props
 * @param {Object}   props.poll
 * @param {string}   props.tab      Tab to show first: details, questions or results.
 * @param {Function} props.onChange Receives the saved poll.
 * @param {Function} props.onAction ( action, poll ) to publish or close it.
 * @param {Function} props.onClose
 */
export default function PollPanel( { poll, tab, onChange, onAction, onClose } ) {
	// Key of the question being edited, or NEW_QUESTION.
	const [ editing, setEditing ] = useState( null );
	// Remounts the question editor on its saved copy after each save.
	const [ saveCount, setSaveCount ] = useState( 0 );
	const [ tabName, setTabName ] = useState( tab );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();

	const isClosed = poll.status === 'closed';
	const path = `/polls/${ poll.id }`;
	const reload = async () => onChange( await request( path ) );

	const edited =
		editing && editing !== NEW_QUESTION
			? poll.questions.find( ( question ) => question.key === editing )
			: null;

	const saveQuestion = async ( question ) => {
		const isNew = editing === NEW_QUESTION;
		const questions = isNew
			? [ ...poll.questions, question ]
			: poll.questions.map( ( item ) =>
					item.key === editing ? question : item
			  );
		// Errors go to the question editor, which keeps the edits.
		onChange(
			await request( path, {
				method: 'PUT',
				data: { revision: poll.revision, questions },
			} )
		);
		setEditing( question.key );
		setSaveCount( ( count ) => count + 1 );
		notify.success(
			isNew
				? __( 'Question added.', 'open-bulk-buying' )
				: __( 'Question saved.', 'open-bulk-buying' )
		);
	};

	if ( ! isClosed && ( editing === NEW_QUESTION || edited ) ) {
		return (
			<>
				<FieldPanel
					key={ `${ editing }-${ saveCount }` }
					field={ edited }
					schema={ { fields: poll.questions } }
					config={ QUESTION_CONFIG }
					// A draft has no answers yet, so its questions can still change freely.
					isLocked={ poll.status === 'published' && !! edited }
					onSave={ saveQuestion }
					onReload={ reload }
					onClose={ onClose }
					onCancel={ () => setEditing( null ) }
					onBack={ () => guard( () => setEditing( null ) ) }
					backTitle={ pollTitle( poll ) }
				/>
				{ discardDialog }
			</>
		);
	}

	const tabs = [
		{ name: 'details', title: __( 'Details', 'open-bulk-buying' ) },
		{ name: 'questions', title: __( 'Questions', 'open-bulk-buying' ) },
		...( poll.status === 'draft'
			? []
			: [ { name: 'results', title: __( 'Results', 'open-bulk-buying' ) } ] ),
	];

	return (
		<SidePanel
			title={ pollTitle( poll ) }
			onClose={ onClose }
			tabs={ tabs }
			initialTabName={ tabName }
		>
			{ ( current ) => {
				if ( current.name === 'results' ) {
					return <PollResults poll={ poll } />;
				}
				if ( current.name === 'questions' ) {
					return (
						<QuestionsTab
							poll={ poll }
							onChange={ onChange }
							onReload={ reload }
							onEdit={ ( key ) => {
								// Back from the question returns to this tab.
								setTabName( 'questions' );
								setEditing( key );
							} }
						/>
					);
				}
				return (
					<DetailsTab
						poll={ poll }
						onChange={ onChange }
						onReload={ reload }
						onAction={ onAction }
					/>
				);
			} }
		</SidePanel>
	);
}
