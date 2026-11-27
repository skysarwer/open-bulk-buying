import { SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	FieldPanel,
	LocalizedTextControl,
} from '../../../components/field-editor';
import { profileFieldAdminLabel } from '../../../utils/config';
import { PROFILE_EXTRAS } from './utils';

export const TYPE_LABELS = {
	text: __( 'Text', 'open-bulk-buying' ),
	boolean: __( 'True/false', 'open-bulk-buying' ),
	options: __( 'Options', 'open-bulk-buying' ),
};

function requiredHelp( type, visibility ) {
	if ( visibility === 'staff' ) {
		return type === 'boolean'
			? __(
					'Staff must check the box when adding a member. Later edits are never blocked.',
					'open-bulk-buying'
			  )
			: __(
					'Staff must fill this in when adding a member. Later edits are never blocked.',
					'open-bulk-buying'
			  );
	}
	if ( type === 'boolean' ) {
		return __(
			'Members must check the box (for example, to give consent).',
			'open-bulk-buying'
		);
	}
	return __(
		'Asked when an account is created. Existing members must answer the next time they update their profile. Staff edits are never blocked.',
		'open-bulk-buying'
	);
}

// What profile fields ask of the shared field editor.
const CONFIG = {
	extras: PROFILE_EXTRAS,
	styles: [ 'select', 'chips' ],
	typeLabels: TYPE_LABELS,
	staleCode: 'openbb_profile_fields_stale',
	strings: {
		newTitle: __( 'New profile field', 'open-bulk-buying' ),
		add: __( 'Add field', 'open-bulk-buying' ),
		reload: __( 'Reload fields', 'open-bulk-buying' ),
		fallbackKey: 'field',
		keyHelp: __(
			'Stores the answers. It follows the label until you edit it, and can’t change after the field is saved.',
			'open-bulk-buying'
		),
		keyLocked: __(
			'The key can’t change once the field is saved, because members’ answers are stored under it.',
			'open-bulk-buying'
		),
		typeLocked: __(
			'The type can’t change after saving. Archive this field and add a new one instead.',
			'open-bulk-buying'
		),
		archived: __(
			'This field is archived: it is hidden from forms, and existing answers are kept.',
			'open-bulk-buying'
		),
	},
	title: ( draft ) => profileFieldAdminLabel( draft ),
	afterLabel: ( { draft, change } ) => (
		<LocalizedTextControl
			label={ __( 'Admin label', 'open-bulk-buying' ) }
			value={ draft.admin_label }
			onChange={ ( adminLabel ) => change( { admin_label: adminLabel } ) }
			help={ __(
				'Optional label for admin screens only. Leave blank to use the regular label. Members still see the regular label.',
				'open-bulk-buying'
			) }
		/>
	),
	settings: ( { draft, change } ) => (
		<>
			<h3 className="openbb-section-title">
				{ __( 'Who answers', 'open-bulk-buying' ) }
			</h3>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Visibility', 'open-bulk-buying' ) }
				value={ draft.visibility }
				onChange={ ( visibility ) => change( { visibility } ) }
				options={ [
					{
						value: 'member',
						label: __( 'Members and staff', 'open-bulk-buying' ),
					},
					{
						value: 'staff',
						label: __( 'Staff only', 'open-bulk-buying' ),
					},
				] }
				help={
					draft.visibility === 'staff'
						? __(
								'Only staff see and fill in this field. It is not secret: it is included when a member requests an export of their personal data.',
								'open-bulk-buying'
						  )
						: __(
								'Asked on the signup form and shown on the member’s account.',
								'open-bulk-buying'
						  )
				}
			/>
			{ draft.visibility === 'member' && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Members can change their answer later',
						'open-bulk-buying'
					) }
					checked={ draft.member_editable }
					onChange={ ( memberEditable ) =>
						change( { member_editable: memberEditable } )
					}
				/>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={
					draft.type === 'boolean'
						? __( 'Required (must be checked)', 'open-bulk-buying' )
						: __( 'Required', 'open-bulk-buying' )
				}
				checked={ draft.required }
				onChange={ ( required ) => change( { required } ) }
				help={ requiredHelp( draft.type, draft.visibility ) }
			/>
		</>
	),
	// Staff entered these values; members will now see them.
	confirmSave: ( field, draft ) =>
		field?.visibility === 'staff' && draft.visibility === 'member'
			? {
					message: __(
						'Members will see and be able to edit the answers staff entered in this field. Continue?',
						'open-bulk-buying'
					),
					confirmLabel: __( 'Show to members', 'open-bulk-buying' ),
			  }
			: null,
	preview: {
		note: ( draft ) =>
			draft.visibility === 'staff'
				? __(
						'Staff only: this field never appears on member forms.',
						'open-bulk-buying'
				  )
				: '',
		shownOn: ( draft ) =>
			draft.visibility === 'staff'
				? __( 'the Members screen', 'open-bulk-buying' )
				: __(
						'signup, member account and the Members screen',
						'open-bulk-buying'
				  ),
	},
};

/**
 * Creates or edits one profile field. Saving writes the whole field list with
 * this field replaced (or added), via `onSave( field )`.
 *
 * @param {Object}      props
 * @param {Object|null} props.field    The saved field, or null for a new one.
 * @param {Object}      props.schema   The loaded schema (for key checks).
 * @param {Function}    props.onSave   field => Promise.
 * @param {Function}    props.onReload Reloads the schema after a 409.
 * @param {Function}    props.onClose
 */
export default function ProfileFieldPanel( props ) {
	return <FieldPanel { ...props } config={ CONFIG } />;
}
