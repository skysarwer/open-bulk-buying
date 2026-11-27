/**
 * Pure helpers for the profile field editor. The shared field editor
 * (components/field-editor) does the work; this adds what profile fields have
 * of their own.
 */
import {
	fromDraft as fieldFromDraft,
	toDraft as fieldToDraft,
} from '../../../components/field-editor/utils';

export * from '../../../components/field-editor/utils';

// Settings only profile fields have, with their value for a new field.
export const PROFILE_EXTRAS = {
	admin_label: { default: '' },
	required: false,
	visibility: 'member',
	member_editable: true,
};

/**
 * The editor's working copy of a profile field.
 *
 * @param {Object|null} field Saved field, or null for a new one.
 * @return {Object} Draft.
 */
export const toDraft = ( field ) =>
	fieldToDraft( field, { extras: PROFILE_EXTRAS } );

/**
 * A draft → the profile field definition the API takes.
 *
 * @param {Object} draft
 * @return {Object} Field.
 */
export const fromDraft = ( draft ) =>
	fieldFromDraft( draft, { extras: PROFILE_EXTRAS } );
