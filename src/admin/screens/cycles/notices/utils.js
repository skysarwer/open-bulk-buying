/**
 * Pure helpers for the Notices tab: summaries of a notice's settings in words,
 * the editable settings as a draft, and what is wrong with a draft.
 *
 * Mirrors the rules in OPENBB_Cycle_Notices; the server checks them again.
 */
import { __, sprintf } from '@wordpress/i18n';

import { localized } from '../../../utils/config';
import { formatDateTime, stageLabel, stages } from '../polls/utils';

export const CONTENT_MAX = 1000;
export const CTA_TEXT_MAX = 60;
export const CTA_URL_MAX = 2048;
export const STALE = 'openbb_notice_stale';

// Notices are info (blue) or alert (yellow); green and red mean order state.
export const TYPE_LABELS = {
	info: __( 'Info', 'open-bulk-buying' ),
	alert: __( 'Alert', 'open-bulk-buying' ),
};

export const typeLabel = ( notice ) =>
	TYPE_LABELS[ notice.type ] || notice.type;

// Lifecycle order, for the status filter.
export const PHASES = [ 'draft', 'scheduled', 'open', 'ended' ];

const TITLE_LENGTH = 80;

/**
 * A notice's text on one line, for lists and headings.
 *
 * @param {Object} notice
 * @return {string} Text.
 */
export function noticeTitle( notice ) {
	const text = localized( notice.content ).replace( /\s+/g, ' ' ).trim();
	if ( ! text ) {
		return __( '(Empty notice)', 'open-bulk-buying' );
	}
	return text.length > TITLE_LENGTH
		? `${ text.slice( 0, TITLE_LENGTH - 1 ).trimEnd() }…`
		: text;
}

export const hasCta = ( notice ) =>
	!! ( localized( notice.cta_text ) && notice.cta_url );

export function opensSummary( notice ) {
	switch ( notice.open_rule ) {
		case 'publish':
			return __( 'Shown as soon as it is published', 'open-bulk-buying' );
		case 'date':
			return sprintf(
				/* translators: %s: date and time. */
				__( 'Shown from %s', 'open-bulk-buying' ),
				formatDateTime( notice.open_at )
			);
		default:
			return sprintf(
				/* translators: %s: cycle stage, e.g. Ordering. */
				__( 'Shown once the cycle reaches %s', 'open-bulk-buying' ),
				stageLabel( notice.open_stage )
			);
	}
}

export function closesSummary( notice ) {
	switch ( notice.close_rule ) {
		case 'manual':
			return __( 'Stays up until you unpublish it', 'open-bulk-buying' );
		case 'date':
			return sprintf(
				/* translators: %s: date and time. */
				__( 'Hidden from %s', 'open-bulk-buying' ),
				formatDateTime( notice.close_at )
			);
		default:
			return sprintf(
				/* translators: %s: cycle stage, e.g. Processing. */
				__( 'Hidden once the cycle reaches %s', 'open-bulk-buying' ),
				stageLabel( notice.close_stage )
			);
	}
}

// The API sends dates in the site's timezone with its offset, and reads a date
// without an offset as site time, so a datetime-local input can hold the first
// 16 characters as they are.
const toInputDate = ( value ) => ( value ? value.slice( 0, 16 ) : '' );

/**
 * A new notice's settings: an info notice that shows once published.
 *
 * @return {Object} Draft.
 */
export const blankSettings = () => ( {
	type: 'info',
	content: { default: '' },
	open_rule: 'publish',
	open_stage: 'ordering',
	open_at: '',
	close_rule: 'manual',
	close_stage: 'processing',
	close_at: '',
	cta_text: { default: '' },
	cta_url: '',
} );

/**
 * The settings of a notice as the editor's working copy.
 *
 * @param {Object|null} notice Null for a notice not yet created.
 * @return {Object} Draft.
 */
export const toSettings = ( notice ) =>
	notice
		? {
				type: notice.type,
				content: notice.content,
				open_rule: notice.open_rule,
				open_stage: notice.open_stage,
				open_at: toInputDate( notice.open_at ),
				close_rule: notice.close_rule,
				close_stage: notice.close_stage,
				close_at: toInputDate( notice.close_at ),
				cta_text: notice.cta_text,
				cta_url: notice.cta_url,
		  }
		: blankSettings();

/**
 * A settings draft → what the API takes.
 *
 * @param {Object} draft
 * @return {Object} Request data.
 */
export const fromSettings = ( draft ) => ( {
	...draft,
	cta_url: draft.cta_url.trim(),
	open_at: draft.open_rule === 'date' ? draft.open_at : null,
	close_at: draft.close_rule === 'date' ? draft.close_at : null,
} );

/**
 * Everything wrong with a settings draft, as messages (empty when it can be saved).
 *
 * @param {Object} draft
 * @return {string[]} Messages.
 */
export function settingsProblems( draft ) {
	const problems = [];
	const order = stages();

	if ( ! ( draft.content?.default || '' ).trim() ) {
		problems.push( __( 'Write the notice text.', 'open-bulk-buying' ) );
	}
	const hasText = !! ( draft.cta_text?.default || '' ).trim();
	const hasUrl = !! draft.cta_url.trim();
	if ( hasText !== hasUrl ) {
		problems.push(
			__(
				'Add both the button text and its link, or leave both empty.',
				'open-bulk-buying'
			)
		);
	}
	if ( draft.open_rule === 'date' && ! draft.open_at ) {
		problems.push(
			__(
				'Set the date and time the notice is shown from.',
				'open-bulk-buying'
			)
		);
	}
	if ( draft.close_rule === 'date' && ! draft.close_at ) {
		problems.push(
			__(
				'Set the date and time the notice is hidden from.',
				'open-bulk-buying'
			)
		);
	}
	if ( draft.close_rule === 'stage' ) {
		// Every cycle has reached its first stage, so closing there never shows it.
		const opens =
			draft.open_rule === 'stage' ? order.indexOf( draft.open_stage ) : 0;
		if ( order.indexOf( draft.close_stage ) <= opens ) {
			problems.push(
				__(
					'The notice must be hidden at a later cycle stage than the one it is shown at.',
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
			__(
				'The notice must be hidden after it is shown.',
				'open-bulk-buying'
			)
		);
	}

	return problems;
}
