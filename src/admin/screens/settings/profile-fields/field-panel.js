import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalNumberControl as NumberControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ErrorNotice, SidePanel } from '../../../components';
import { discardUnsavedChanges, useUnsavedChanges } from '../../../hooks';
import LocalizedTextControl from './localized-text-control';
import OptionsEditor from './options-editor';
import FieldPreview from './preview';
import {
	MAX_MAX_LENGTH,
	cleanKeyInput,
	draftProblems,
	fieldKeyProblem,
	fromDraft,
	keyFromLabel,
	toDraft,
	uniqueKey,
} from './utils';

const STALE = 'openbb_profile_fields_stale';

export const TYPE_LABELS = {
	text: __( 'Text', 'open-bulk-buying' ),
	boolean: __( 'True/false', 'open-bulk-buying' ),
	options: __( 'Options', 'open-bulk-buying' ),
};

function requiredHelp( type ) {
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

function Heading( { children } ) {
	return <h3 className="openbb-section-title">{ children }</h3>;
}

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
export default function FieldPanel( {
	field,
	schema,
	onSave,
	onReload,
	onClose,
} ) {
	const isNew = ! field;
	const initial = useMemo( () => toDraft( field ), [] ); // eslint-disable-line react-hooks/exhaustive-deps
	const [ draft, setDraft ] = useState( initial );
	const [ error, setError ] = useState( null );
	const [ showProblems, setShowProblems ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );

	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	useUnsavedChanges( isDirty );

	const otherKeys = new Set(
		schema.fields
			.map( ( item ) => item.key )
			.filter( ( key ) => key !== field?.key )
	);
	const problems = draftProblems( draft, otherKeys, schema );
	const keyProblem =
		isNew && draft.key
			? fieldKeyProblem( draft.key, otherKeys, schema )
			: null;

	const change = ( changes ) =>
		setDraft( ( prev ) => {
			const next = { ...prev, ...changes };
			// A new field's key follows its label until the key is edited.
			if ( isNew && ! next.keyTouched && changes.label ) {
				const taken = new Set( [
					...otherKeys,
					...( schema.reserved_keys || [] ),
					...( schema.retired_keys || [] ),
				] );
				next.key = uniqueKey(
					keyFromLabel( next.label.default ),
					taken,
					'field'
				);
			}
			return next;
		} );

	const save = async () => {
		setConfirming( false );
		setIsBusy( true );
		setError( null );
		try {
			await onSave( fromDraft( draft ) );
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	const submit = ( event ) => {
		event.preventDefault();
		if ( problems.length ) {
			setShowProblems( true );
			return;
		}
		// Staff entered these values; members will now see them.
		if ( field?.visibility === 'staff' && draft.visibility === 'member' ) {
			setConfirming( true );
			return;
		}
		save();
	};

	const cancel = () => {
		discardUnsavedChanges();
		onClose();
	};

	const title = isNew
		? __( 'New profile field', 'open-bulk-buying' )
		: draft.label.default || field.key;

	return (
		<SidePanel title={ title } onClose={ onClose }>
			<form onSubmit={ submit } className="openbb-field-editor">
				<VStack spacing={ 4 }>
					{ error?.code === STALE ? (
						<Notice
							status="error"
							isDismissible={ false }
							actions={ [
								{
									label: __(
										'Reload fields',
										'open-bulk-buying'
									),
									onClick: async () => {
										await onReload();
										setError( null );
									},
								},
							] }
						>
							{ error.message }{ ' ' }
							{ __(
								'Your edits here are kept: reload, then save again.',
								'open-bulk-buying'
							) }
						</Notice>
					) : (
						<ErrorNotice
							error={ error }
							onDismiss={ () => setError( null ) }
						/>
					) }
					{ showProblems && problems.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul className="openbb-problems">
								{ problems.map( ( problem ) => (
									<li key={ problem }>{ problem }</li>
								) ) }
							</ul>
						</Notice>
					) }
					{ draft.archived && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'This field is archived: it is hidden from forms, and existing answers are kept.',
								'open-bulk-buying'
							) }
						</Notice>
					) }

					<LocalizedTextControl
						label={ __( 'Label', 'open-bulk-buying' ) }
						value={ draft.label }
						onChange={ ( label ) => change( { label } ) }
					/>

					{ isNew ? (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Key', 'open-bulk-buying' ) }
							value={ draft.key }
							onChange={ ( key ) =>
								change( {
									key: cleanKeyInput( key ),
									keyTouched: true,
								} )
							}
							help={
								keyProblem ||
								__(
									'Stores the answers. It follows the label until you edit it, and can’t change after the field is saved.',
									'open-bulk-buying'
								)
							}
							className={ keyProblem ? 'has-error' : undefined }
						/>
					) : (
						<div>
							<div className="openbb-field-editor__label">
								{ __( 'Key', 'open-bulk-buying' ) }
							</div>
							<code>{ field.key }</code>
							<p className="openbb-muted">
								{ __(
									'The key can’t change once the field is saved, because members’ answers are stored under it.',
									'open-bulk-buying'
								) }
							</p>
						</div>
					) }

					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Type', 'open-bulk-buying' ) }
						value={ draft.type }
						disabled={ ! isNew }
						onChange={ ( type ) => change( { type } ) }
						options={ Object.entries( TYPE_LABELS ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						help={
							isNew
								? undefined
								: __(
										'The type can’t change after saving. Archive this field and add a new one instead.',
										'open-bulk-buying'
								  )
						}
					/>

					<LocalizedTextControl
						label={ __( 'Help text', 'open-bulk-buying' ) }
						value={ draft.help }
						onChange={ ( help ) => change( { help } ) }
					/>

					{ draft.type === 'text' && (
						<>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Several lines',
									'open-bulk-buying'
								) }
								checked={ draft.text.multiline }
								onChange={ ( multiline ) =>
									change( {
										text: { ...draft.text, multiline },
									} )
								}
							/>
							<NumberControl
								__next40pxDefaultSize
								label={ __(
									'Maximum length (characters)',
									'open-bulk-buying'
								) }
								min={ 1 }
								max={ MAX_MAX_LENGTH }
								value={ draft.text.max_length }
								onChange={ ( value ) =>
									change( {
										text: {
											...draft.text,
											max_length:
												value === ''
													? ''
													: Number( value ),
										},
									} )
								}
							/>
						</>
					) }

					{ draft.type === 'options' && (
						<>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Allow several choices',
									'open-bulk-buying'
								) }
								checked={ draft.options.multiple }
								onChange={ ( multiple ) =>
									change( {
										options: {
											multiple,
											style: multiple
												? 'chips'
												: draft.options.style,
										},
									} )
								}
							/>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Style', 'open-bulk-buying' ) }
								value={ draft.options.style }
								onChange={ ( style ) =>
									change( {
										options: { ...draft.options, style },
									} )
								}
								options={ [
									{
										value: 'select',
										label: __(
											'Dropdown',
											'open-bulk-buying'
										),
										disabled: draft.options.multiple,
									},
									{
										value: 'chips',
										label: __(
											'Chips',
											'open-bulk-buying'
										),
									},
								] }
								help={
									draft.options.multiple
										? __(
												'Several choices are shown as chips.',
												'open-bulk-buying'
										  )
										: undefined
								}
							/>
							<Heading>
								{ __( 'Options', 'open-bulk-buying' ) }
							</Heading>
							<OptionsEditor
								rows={ draft.rows }
								onChange={ ( rows ) => change( { rows } ) }
							/>
						</>
					) }

					<Heading>
						{ __( 'Who answers', 'open-bulk-buying' ) }
					</Heading>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Visibility', 'open-bulk-buying' ) }
						value={ draft.visibility }
						onChange={ ( visibility ) => change( { visibility } ) }
						options={ [
							{
								value: 'member',
								label: __(
									'Members and staff',
									'open-bulk-buying'
								),
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
								? __(
										'Required (must be checked)',
										'open-bulk-buying'
								  )
								: __( 'Required', 'open-bulk-buying' )
						}
						checked={ draft.required }
						onChange={ ( required ) => change( { required } ) }
						help={ requiredHelp( draft.type ) }
					/>

					<Heading>{ __( 'Preview', 'open-bulk-buying' ) }</Heading>
					<FieldPreview draft={ draft } />

					<HStack justify="flex-end" className="openbb-form-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ cancel }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ( ! isNew && ! isDirty ) }
							accessibleWhenDisabled
						>
							{ isNew
								? __( 'Add field', 'open-bulk-buying' )
								: __( 'Save', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
			<ConfirmDialog
				isOpen={ confirming }
				confirmButtonText={ __(
					'Show to members',
					'open-bulk-buying'
				) }
				onConfirm={ save }
				onCancel={ () => setConfirming( false ) }
			>
				{ __(
					'Members will see and be able to edit the answers staff entered in this field. Continue?',
					'open-bulk-buying'
				) }
			</ConfirmDialog>
		</SidePanel>
	);
}
