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

import ErrorNotice from '../error-notice';
import SidePanel from '../side-panel';
import { discardUnsavedChanges, useUnsavedChanges } from '../../hooks';
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

const STYLE_LABELS = {
	select: __( 'Dropdown', 'open-bulk-buying' ),
	chips: __( 'Chips', 'open-bulk-buying' ),
	stars: __( 'Star rating (1 to 5)', 'open-bulk-buying' ),
};

function Heading( { children } ) {
	return <h3 className="openbb-section-title">{ children }</h3>;
}

/**
 * Creates or edits one field definition: a profile field or a poll question.
 * Saving hands the definition to `onSave( field )`; the caller stores it.
 *
 * What differs between consumers comes from `config`:
 * - extras          Their own settings and each one's value for a new field
 *                   (e.g. required, visibility). Kept in the draft and saved.
 * - styles          Styles an options field can use; 'stars' is a rating.
 * - typeLabels      type => label.
 * - staleCode       Error code of a save based on an outdated copy.
 * - lockChoiceMode  Once locked, "several choices" and rating can't change.
 * - strings         newTitle, add, reload, keyHelp, keyLocked, typeLocked,
 *                   archived, keyInUse, fallbackKey.
 * - title           draft => panel title of an existing field.
 * - afterLabel      ( { draft, change } ) => controls under the label.
 * - settings        ( { draft, change } ) => the consumer's own section.
 * - confirmSave     ( field, draft ) => question to confirm first, or null.
 * - preview         Options for FieldPreview.
 *
 * @param {Object}      props
 * @param {Object|null} props.field    The saved field, or null for a new one.
 * @param {Object}      props.schema   fields, reserved_keys, retired_keys (for key checks).
 * @param {Object}      props.config   See above.
 * @param {boolean}     props.isLocked Answers may depend on this field: its type
 *                                     and saved options can't change. Default:
 *                                     every saved field.
 * @param {Function}    props.onSave    field => Promise.
 * @param {Function}    props.onReload  Reloads the schema after a 409.
 * @param {Function}    props.onClose
 * @param {Function}    props.onCancel  After Cancel has discarded the edits. Default: onClose.
 * @param {Function}    props.onBack    Shows a back button in the header.
 * @param {string}      props.backTitle Title of what `onBack` returns to.
 */
export default function FieldPanel( {
	field,
	schema,
	config,
	isLocked = !! field,
	onSave,
	onReload,
	onClose,
	onCancel = onClose,
	onBack,
	backTitle,
} ) {
	const isNew = ! field;
	const { strings } = config;
	const initial = useMemo(
		() => toDraft( field, config, isLocked ? field : null ),
		[] // eslint-disable-line react-hooks/exhaustive-deps
	);
	const [ draft, setDraft ] = useState( initial );
	const [ error, setError ] = useState( null );
	const [ showProblems, setShowProblems ] = useState( false );
	const [ confirming, setConfirming ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	useUnsavedChanges( isDirty );

	const otherKeys = new Set(
		schema.fields
			.map( ( item ) => item.key )
			.filter( ( key ) => key !== field?.key )
	);
	const problems = draftProblems( draft, otherKeys, schema, config );
	const keyProblem =
		isNew && draft.key
			? fieldKeyProblem( draft.key, otherKeys, schema, config )
			: null;
	const isStars = draft.options.style === 'stars';
	const choiceLocked = isLocked && !! config.lockChoiceMode;

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
					strings.fallbackKey
				);
			}
			return next;
		} );

	const save = async () => {
		setConfirming( null );
		setIsBusy( true );
		setError( null );
		try {
			await onSave( fromDraft( draft, config ) );
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
		const question = config.confirmSave?.( field, draft );
		if ( question ) {
			setConfirming( question );
			return;
		}
		save();
	};

	const cancel = () => {
		discardUnsavedChanges();
		onCancel();
	};

	return (
		<SidePanel
			title={ isNew ? strings.newTitle : config.title( draft ) }
			onClose={ onClose }
			onBack={ onBack }
			backTitle={ backTitle }
		>
			<form onSubmit={ submit } className="openbb-field-editor">
				<VStack spacing={ 4 }>
					{ error?.code === config.staleCode ? (
						<Notice
							status="error"
							isDismissible={ false }
							actions={ [
								{
									label: strings.reload,
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
							{ strings.archived }
						</Notice>
					) }

					<LocalizedTextControl
						label={ __( 'Label', 'open-bulk-buying' ) }
						value={ draft.label }
						onChange={ ( label ) => change( { label } ) }
					/>

					{ config.afterLabel?.( { draft, change } ) }

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
							help={ keyProblem || strings.keyHelp }
							className={ keyProblem ? 'has-error' : undefined }
						/>
					) : (
						<div>
							<div className="openbb-field-editor__label">
								{ __( 'Key', 'open-bulk-buying' ) }
							</div>
							<code>{ field.key }</code>
							<p className="openbb-muted">
								{ strings.keyLocked }
							</p>
						</div>
					) }

					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Type', 'open-bulk-buying' ) }
						value={ draft.type }
						disabled={ isLocked }
						onChange={ ( type ) => change( { type } ) }
						options={ Object.entries( config.typeLabels ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						help={ isLocked ? strings.typeLocked : undefined }
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
							{ ! isStars && (
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Allow several choices',
										'open-bulk-buying'
									) }
									checked={ draft.options.multiple }
									disabled={ choiceLocked }
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
							) }
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Style', 'open-bulk-buying' ) }
								value={ draft.options.style }
								disabled={ choiceLocked && isStars }
								onChange={ ( style ) =>
									change( {
										options: {
											style,
											multiple:
												style === 'stars'
													? false
													: draft.options.multiple,
										},
									} )
								}
								options={ config.styles.map( ( style ) => ( {
									value: style,
									label: STYLE_LABELS[ style ],
									disabled:
										( style === 'select' &&
											draft.options.multiple ) ||
										( style === 'stars' &&
											( draft.options.multiple ||
												choiceLocked ) ),
								} ) ) }
								help={
									draft.options.multiple
										? __(
												'Several choices are shown as chips.',
												'open-bulk-buying'
										  )
										: undefined
								}
							/>
							{ isStars ? (
								<p className="openbb-muted">
									{ __(
										'Members choose from one to five stars. The five ratings are fixed, so there are no options to edit. Results show the average.',
										'open-bulk-buying'
									) }
								</p>
							) : (
								<>
									<Heading>
										{ __( 'Options', 'open-bulk-buying' ) }
									</Heading>
									<OptionsEditor
										rows={ draft.rows }
										onChange={ ( rows ) =>
											change( { rows } )
										}
									/>
								</>
							) }
						</>
					) }

					{ config.settings?.( { draft, change } ) }

					<Heading>{ __( 'Preview', 'open-bulk-buying' ) }</Heading>
					<FieldPreview draft={ draft } options={ config.preview } />

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
								? strings.add
								: __( 'Save', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
			<ConfirmDialog
				isOpen={ !! confirming }
				confirmButtonText={ confirming?.confirmLabel }
				onConfirm={ save }
				onCancel={ () => setConfirming( null ) }
			>
				{ confirming?.message }
			</ConfirmDialog>
		</SidePanel>
	);
}
