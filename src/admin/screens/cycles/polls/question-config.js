import { __ } from '@wordpress/i18n';

import { localized } from '../../../utils/config';
import { QUESTION_TYPE_LABELS, STALE } from './utils';

/**
 * What poll questions ask of the shared field editor. Unlike profile fields
 * they are never required, have no visibility or admin label, and a
 * single-choice question can be a star rating. A yes/no question is two choices
 * that start unselected, so "No" is an answer and not the absence of one.
 */
const QUESTION_CONFIG = {
	extras: {},
	styles: [ 'select', 'chips', 'stars' ],
	typeLabels: QUESTION_TYPE_LABELS,
	staleCode: STALE,
	lockChoiceMode: true,
	strings: {
		newTitle: __( 'New question', 'open-bulk-buying' ),
		add: __( 'Add question', 'open-bulk-buying' ),
		reload: __( 'Reload poll', 'open-bulk-buying' ),
		fallbackKey: 'question',
		keyInUse: __(
			'Another question already uses this key.',
			'open-bulk-buying'
		),
		keyHelp: __(
			'Stores the answers. It follows the label until you edit it, and can’t change after the question is added.',
			'open-bulk-buying'
		),
		keyLocked: __(
			'The key can’t change, because members’ answers are stored under it.',
			'open-bulk-buying'
		),
		typeLocked: __(
			'The type can’t change once the poll is published. Archive this question and add a new one instead.',
			'open-bulk-buying'
		),
		archived: __(
			'This question is archived: members are no longer asked it, and answers already given are kept.',
			'open-bulk-buying'
		),
	},
	title: ( draft ) => localized( draft.label ) || draft.key,
	preview: {
		yesNo: true,
		clearable: true,
		note: () =>
			__(
				'Members can skip this question: poll questions are never required.',
				'open-bulk-buying'
			),
	},
};

export default QUESTION_CONFIG;
