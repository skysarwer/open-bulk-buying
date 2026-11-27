/**
 * Pure helpers for the Polls tab: summaries of a poll's settings in words, the
 * editable settings as a draft, and what is wrong with a draft.
 *
 * Mirrors the rules in OPENBB_Polls; the server checks them again.
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

import { config, localized } from '../../../utils/config';

export const TITLE_MAX = 200;
export const INTRO_MAX = 2000;
export const MAX_QUESTIONS = 50;
export const STALE = 'openbb_poll_stale';

export const formatDateTime = ( value ) =>
	value ? dateI18n( getSettings().formats.datetime, value ) : '';

// Cycle stages in lifecycle order.
export const stages = () => Object.keys( config.cycleStatuses );
export const stageLabel = ( stage ) => config.cycleStatuses[ stage ] || stage;

export const pollTitle = ( poll ) =>
	localized( poll.title ) || __( '(Untitled poll)', 'open-bulk-buying' );

// What the editor is set to while a question is being added.
export const NEW_QUESTION = '__new';

export const questionLabel = ( question ) =>
	localized( question.label ) || question.key;

export const activeQuestionCount = ( poll ) =>
	poll.questions.filter( ( question ) => ! question.archived ).length;

export const questionsLabel = ( count ) =>
	sprintf(
		/* translators: %d: number of questions members are asked. */
		_n( '%d question', '%d questions', count, 'open-bulk-buying' ),
		count
	);

export const isStars = ( question ) =>
	question.type === 'options' && question.options?.style === 'stars';

export const QUESTION_TYPE_LABELS = {
	text: __( 'Text', 'open-bulk-buying' ),
	boolean: __( 'Yes/No', 'open-bulk-buying' ),
	options: __( 'Options', 'open-bulk-buying' ),
};

export const questionTypeLabel = ( question ) =>
	isStars( question )
		? __( 'Star rating', 'open-bulk-buying' )
		: QUESTION_TYPE_LABELS[ question.type ];

export function opensSummary( poll ) {
	switch ( poll.open_rule ) {
		case 'publish':
			return __( 'Opens as soon as it is published', 'open-bulk-buying' );
		case 'date':
			return sprintf(
				/* translators: %s: date and time. */
				__( 'Opens on %s', 'open-bulk-buying' ),
				formatDateTime( poll.open_at )
			);
		default:
			return sprintf(
				/* translators: %s: cycle stage, e.g. Ordering. */
				__( 'Opens when the cycle reaches %s', 'open-bulk-buying' ),
				stageLabel( poll.open_stage )
			);
	}
}

export function closesSummary( poll ) {
	switch ( poll.close_rule ) {
		case 'manual':
			return __( 'Stays open until staff close it', 'open-bulk-buying' );
		case 'date':
			return sprintf(
				/* translators: %s: date and time. */
				__( 'Closes on %s', 'open-bulk-buying' ),
				formatDateTime( poll.close_at )
			);
		default:
			return sprintf(
				/* translators: %s: cycle stage, e.g. Processing. */
				__( 'Closes when the cycle reaches %s', 'open-bulk-buying' ),
				stageLabel( poll.close_stage )
			);
	}
}

/**
 * Who can answer, in words.
 *
 * @param {Object} poll
 * @param {Array}  cycles From /polls/audience-cycles, to name the chosen cycles.
 * @return {string} Summary.
 */
export function audienceSummary( poll, cycles = [] ) {
	if ( poll.audience !== 'ordered_in_cycles' ) {
		return __( 'All active members', 'open-bulk-buying' );
	}
	const names = poll.audience_cycles
		.map( ( id ) => cycles.find( ( cycle ) => cycle.id === id )?.name )
		.filter( Boolean );
	if ( ! names.length ) {
		return sprintf(
			/* translators: %d: number of cycles. */
			_n(
				'Members who ordered in %d chosen cycle',
				'Members who ordered in %d chosen cycles',
				poll.audience_cycles.length,
				'open-bulk-buying'
			),
			poll.audience_cycles.length
		);
	}
	return sprintf(
		/* translators: %s: list of cycle names. */
		__( 'Members who ordered in: %s', 'open-bulk-buying' ),
		names.join( ', ' )
	);
}

export function placementsSummary( poll ) {
	const places = [
		poll.placements.includes( 'card' ) &&
			__( 'card on the order page', 'open-bulk-buying' ),
		poll.placements.includes( 'thank_you' ) &&
			__( 'questions on the order thank-you page', 'open-bulk-buying' ),
	].filter( Boolean );

	return places.length
		? sprintf(
				/* translators: %s: where the poll is shown, e.g. "card on the order page". */
				__( 'Shown as: %s', 'open-bulk-buying' ),
				places.join( ', ' )
		  )
		: __( 'Only on the polls page or by direct link', 'open-bulk-buying' );
}

export const responsesLabel = ( count ) =>
	sprintf(
		/* translators: %d: number of members who responded. */
		_n( '%d response', '%d responses', count, 'open-bulk-buying' ),
		count
	);

// The API sends dates in the site's timezone with its offset, and reads a date
// without an offset as site time, so a datetime-local input can hold the first
// 16 characters as they are.
const toInputDate = ( value ) => ( value ? value.slice( 0, 16 ) : '' );

/**
 * The settings of a poll as the editor's working copy.
 *
 * @param {Object} poll
 * @return {Object} Draft.
 */
export const toSettings = ( poll ) => ( {
	title: poll.title,
	intro: poll.intro,
	open_rule: poll.open_rule,
	open_stage: poll.open_stage,
	open_at: toInputDate( poll.open_at ),
	close_rule: poll.close_rule,
	close_stage: poll.close_stage,
	close_at: toInputDate( poll.close_at ),
	placements: poll.placements,
	audience: poll.audience,
	audience_cycles: poll.audience_cycles,
} );

/**
 * A settings draft → what the API takes.
 *
 * @param {Object} draft
 * @return {Object} Request data.
 */
export const fromSettings = ( draft ) => ( {
	...draft,
	open_at: draft.open_rule === 'date' ? draft.open_at : null,
	close_at: draft.close_rule === 'date' ? draft.close_at : null,
	audience_cycles:
		draft.audience === 'ordered_in_cycles' ? draft.audience_cycles : [],
} );

/**
 * Everything wrong with a settings draft, as messages (empty when it can be saved).
 *
 * @param {Object}  draft
 * @param {boolean} isPublished A published poll must keep a usable audience.
 * @return {string[]} Messages.
 */
export function settingsProblems( draft, isPublished ) {
	const problems = [];
	const order = stages();

	if ( ! ( draft.title?.default || '' ).trim() ) {
		problems.push( __( 'Give the poll a title.', 'open-bulk-buying' ) );
	}
	if ( draft.open_rule === 'date' && ! draft.open_at ) {
		problems.push(
			__( 'Set the date and time the poll opens.', 'open-bulk-buying' )
		);
	}
	if ( draft.close_rule === 'date' && ! draft.close_at ) {
		problems.push(
			__( 'Set the date and time the poll closes.', 'open-bulk-buying' )
		);
	}
	if ( draft.close_rule === 'stage' ) {
		// Every cycle has reached its first stage, so closing there never opens.
		const opens =
			draft.open_rule === 'stage' ? order.indexOf( draft.open_stage ) : 0;
		if ( order.indexOf( draft.close_stage ) <= opens ) {
			problems.push(
				__(
					'The poll must close at a later cycle stage than the one it opens at.',
					'open-bulk-buying'
				)
			);
		}
	}
	if (
		draft.open_rule === 'date' &&
		draft.close_rule === 'date' &&
		draft.open_at &&
		draft.close_at &&
		draft.close_at <= draft.open_at
	) {
		problems.push(
			__( 'The poll must close after it opens.', 'open-bulk-buying' )
		);
	}
	if (
		isPublished &&
		draft.audience === 'ordered_in_cycles' &&
		! draft.audience_cycles.length
	) {
		problems.push(
			__(
				'Choose at least one cycle whose members can answer the poll.',
				'open-bulk-buying'
			)
		);
	}

	return problems;
}

/**
 * A member's answer to a question as text ('' when not answered).
 *
 * @param {Object} question
 * @param {*}      value    Text, true/false or a list of option keys.
 * @return {string} Text.
 */
export function answerText( question, value ) {
	if ( value === undefined || value === null || value === '' ) {
		return '';
	}
	if ( question.type === 'boolean' ) {
		return value
			? __( 'Yes', 'open-bulk-buying' )
			: __( 'No', 'open-bulk-buying' );
	}
	if ( question.type !== 'options' ) {
		return String( value );
	}
	const keys = Array.isArray( value ) ? value : [ value ];
	if ( isStars( question ) ) {
		return keys[ 0 ] ? `${ keys[ 0 ] } / 5` : '';
	}
	const labels = optionLabels( question );
	return keys.map( ( key ) => labels[ key ] || key ).join( ', ' );
}

/**
 * Option key => label of an options question, in order, archived ones included.
 *
 * @param {Object} question
 * @return {Object} Labels.
 */
export const optionLabels = ( question ) =>
	Object.fromEntries(
		( question.options?.groups || [] ).flatMap( ( group ) =>
			group.options.map( ( option ) => [
				option.key,
				localized( option.label ) || option.key,
			] )
		)
	);
