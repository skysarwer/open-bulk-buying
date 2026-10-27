import {
	Button,
	Modal,
	Notice,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import {
	ErrorNotice,
	Loading,
	SortableList,
	moveItem,
} from '../../../components';
import { useDiscardGuard, useNotify, useRecord } from '../../../hooks';
import { request } from '../../../utils/api';
import { localized } from '../../../utils/config';
import Section from '../section';
import SettingsPage from '../settings-page';
import FieldPanel, { TYPE_LABELS } from './field-panel';

const PATH = '/settings/profile-fields';
const NEW = '__new';
const STALE = 'openbb_profile_fields_stale';

function Badges( { field } ) {
	const badges = [
		TYPE_LABELS[ field.type ],
		field.required && __( 'Required', 'open-bulk-buying' ),
		field.visibility === 'staff' && __( 'Staff only', 'open-bulk-buying' ),
		field.archived && __( 'Archived', 'open-bulk-buying' ),
	].filter( Boolean );

	return (
		<span className="openbb-profile-fields__badges">
			{ badges.map( ( badge ) => (
				<span key={ badge } className="openbb-badge">
					{ badge }
				</span>
			) ) }
		</span>
	);
}

/**
 * Asks for the field key before deleting an archived field and every
 * member's answer to it.
 *
 * @param {Object}   props
 * @param {Object}   props.field
 * @param {Function} props.onDone  Receives the new schema.
 * @param {Function} props.onClose
 */
function PurgeModal( { field, onDone, onClose } ) {
	const [ typed, setTyped ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const purge = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onDone(
				await request( `${ PATH }/${ field.key }/purge`, {
					method: 'POST',
					data: { confirmation: typed },
				} )
			);
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Delete field and its data', 'open-bulk-buying' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ purge }>
				<VStack spacing={ 4 }>
					<ErrorNotice
						error={ error }
						onDismiss={ () => setError( null ) }
					/>
					<Text as="p">
						{ sprintf(
							/* translators: %s: field label. */
							__(
								'This deletes the field “%s” and every member’s answer to it. This can’t be undone, and the key can never be used again.',
								'open-bulk-buying'
							),
							localized( field.label ) || field.key
						) }
					</Text>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/* translators: %s: field key. */
						label={ sprintf(
							__( 'Type %s to confirm', 'open-bulk-buying' ),
							field.key
						) }
						value={ typed }
						onChange={ setTyped }
						autoComplete="off"
						spellCheck={ false }
					/>
					<HStack justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || typed !== field.key }
							accessibleWhenDisabled
						>
							{ __( 'Delete permanently', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</Modal>
	);
}

/**
 * Settings tab listing the profile fields, with an editor panel. Every change
 * (save, move, archive) saves the whole list at once.
 *
 * @param {Object}  props
 * @param {Element} props.nav Settings tabs.
 */
export default function ProfileFieldsTab( { nav } ) {
	const {
		record: schema,
		setRecord: setSchema,
		error: loadError,
	} = useRecord( PATH );
	const [ editing, setEditing ] = useState( null );
	const [ saveCount, setSaveCount ] = useState( 0 );
	const [ purging, setPurging ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();

	const reload = async () => {
		setError( null );
		try {
			setSchema( await request( PATH ) );
		} catch ( err ) {
			setError( err );
		}
	};

	const put = ( fields ) =>
		request( PATH, {
			method: 'PUT',
			data: { revision: schema.revision, fields },
		} );

	// List changes are shown at once and undone if the save fails.
	const saveList = async ( fields, message ) => {
		const previous = schema;
		setSchema( { ...schema, fields } );
		setIsBusy( true );
		setError( null );
		try {
			setSchema( await put( fields ) );
			if ( message ) {
				notify.success( message );
			}
		} catch ( err ) {
			setSchema( previous );
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	const saveField = async ( field ) => {
		const isNew = editing === NEW;
		const fields = isNew
			? [ ...schema.fields, field ]
			: schema.fields.map( ( item ) =>
					item.key === editing ? field : item
			  );
		const saved = await put( fields );
		setSchema( saved );
		setEditing( field.key );
		setSaveCount( ( count ) => count + 1 );
		notify.success(
			isNew
				? __( 'Field added.', 'open-bulk-buying' )
				: __( 'Field saved.', 'open-bulk-buying' )
		);
	};

	const setArchived = ( field, archived ) => {
		const run = async () => {
			await saveList(
				schema.fields.map( ( item ) =>
					item.key === field.key ? { ...item, archived } : item
				),
				archived
					? __( 'Field archived.', 'open-bulk-buying' )
					: __( 'Field restored.', 'open-bulk-buying' )
			);
			// The open panel's draft would otherwise save the old state back.
			if ( field.key === editing ) {
				setSaveCount( ( count ) => count + 1 );
			}
		};
		if ( field.key === editing ) {
			guard( run );
		} else {
			run();
		}
	};

	const open = ( key ) => guard( () => setEditing( key ) );
	const close = () => guard( () => setEditing( null ) );

	if ( loadError ) {
		return (
			<SettingsPage nav={ nav }>
				<ErrorNotice error={ loadError } />
			</SettingsPage>
		);
	}
	if ( ! schema ) {
		return (
			<SettingsPage nav={ nav }>
				<Loading />
			</SettingsPage>
		);
	}

	const editedField =
		editing && editing !== NEW
			? schema.fields.find( ( field ) => field.key === editing )
			: null;
	const panel =
		editing === NEW || editedField ? (
			<FieldPanel
				key={ `${ editing }-${ saveCount }` }
				field={ editedField }
				schema={ schema }
				onSave={ saveField }
				onReload={ reload }
				onClose={ close }
			/>
		) : null;

	return (
		<SettingsPage nav={ nav } sidebar={ panel } sidebarSize="wide">
			<VStack spacing={ 6 } className="openbb-settings">
				<Section
					title={ __( 'Profile fields', 'open-bulk-buying' ) }
					actions={
						<Button
							__next40pxDefaultSize
							variant="primary"
							icon={ plus }
							onClick={ () => open( NEW ) }
						>
							{ __( 'Add field', 'open-bulk-buying' ) }
						</Button>
					}
				>
					<Text as="p">
						{ __(
							'Extra questions for members. Member fields appear on the signup form and the member’s account; staff-only fields appear only to staff. Fields appear in this order.',
							'open-bulk-buying'
						) }
					</Text>
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
									onClick: reload,
								},
							] }
						>
							{ error.message }
						</Notice>
					) : (
						<ErrorNotice
							error={ error }
							onDismiss={ () => setError( null ) }
						/>
					) }
					{ schema.fields.length ? (
						<SortableList
							className="openbb-profile-fields"
							items={ schema.fields }
							getKey={ ( field ) => field.key }
							getLabel={ ( field ) =>
								localized( field.label ) || field.key
							}
							onMove={ ( from, to ) =>
								saveList( moveItem( schema.fields, from, to ) )
							}
							disabled={ isBusy }
							itemClassName={ ( field ) =>
								[
									field.archived && 'is-archived',
									field.key === editing && 'is-selected',
								]
									.filter( Boolean )
									.join( ' ' )
							}
							renderItem={ ( field ) => (
								<div className="openbb-profile-fields__item">
									<div className="openbb-profile-fields__summary">
										<Button
											variant="link"
											onClick={ () => open( field.key ) }
										>
											{ localized( field.label ) || field.key }
										</Button>{ ' ' }
										<code>{ field.key }</code>
										<Badges field={ field } />
									</div>
									<HStack
										justify="flex-end"
										spacing={ 1 }
										className="openbb-profile-fields__actions"
									>
										<Button
											size="compact"
											variant="tertiary"
											disabled={ isBusy }
											accessibleWhenDisabled
											onClick={ () =>
												setArchived(
													field,
													! field.archived
												)
											}
										>
											{ field.archived
												? __(
														'Restore',
														'open-bulk-buying'
												  )
												: __(
														'Archive',
														'open-bulk-buying'
												  ) }
										</Button>
										{ field.archived && (
											<Button
												size="compact"
												variant="tertiary"
												isDestructive
												disabled={ isBusy }
												accessibleWhenDisabled
												onClick={ () =>
													setPurging( field )
												}
											>
												{ __(
													'Delete…',
													'open-bulk-buying'
												) }
											</Button>
										) }
									</HStack>
								</div>
							) }
						/>
					) : (
						<Text as="p">
							{ __(
								'No profile fields yet.',
								'open-bulk-buying'
							) }
						</Text>
					) }
					<p className="openbb-muted">
						{ __(
							'Archived fields are hidden from forms but keep members’ answers. Only archived fields can be deleted.',
							'open-bulk-buying'
						) }
					</p>
				</Section>
			</VStack>
			{ purging && (
				<PurgeModal
					field={ purging }
					onClose={ () => setPurging( null ) }
					onDone={ ( next ) => {
						setSchema( next );
						if ( editing === purging.key ) {
							setEditing( null );
						}
						setPurging( null );
						notify.success(
							__(
								'Field and its data deleted.',
								'open-bulk-buying'
							)
						);
					} }
				/>
			) }
			{ discardDialog }
		</SettingsPage>
	);
}
