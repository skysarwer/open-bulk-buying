import {
	Button,
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import { ErrorNotice, Loading } from '../../../components';
import { useDiscardGuard, useNotify, useRecord } from '../../../hooks';
import { request } from '../../../utils/api';
import { config } from '../../../utils/config';
import Section from '../section';
import SettingsPage from '../settings-page';
import RolePanel, { STALE } from './role-panel';

const PATH = '/settings/roles';
const NEW = '__new';

// Other screens read the role names from the boot data, and may open without a page load.
const syncConfig = ( doc ) => {
	config.coopRoles = Object.fromEntries(
		doc.roles.map( ( role ) => [ role.key, role.label ] )
	);
};

function Badges( { role } ) {
	const badges = [
		role.custom && __( 'Custom', 'open-bulk-buying' ),
		role.modified && __( 'Edited', 'open-bulk-buying' ),
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

const memberCount = ( role ) =>
	sprintf(
		/* translators: %d: number of members. */
		_n( '%d member', '%d members', role.members, 'open-bulk-buying' ),
		role.members
	);

/**
 * Settings tab for team roles and their permissions. Until something is changed this
 * shows the built-in roles, and nothing is stored.
 *
 * @param {Object}  props
 * @param {Element} props.nav Settings tabs.
 */
export default function RolesTab( { nav } ) {
	const { record: doc, setRecord, error: loadError } = useRecord( PATH );
	const [ editing, setEditing ] = useState( null );
	const [ saveCount, setSaveCount ] = useState( 0 );
	const [ confirm, setConfirm ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();

	const setDoc = ( next ) => {
		syncConfig( next );
		setRecord( next );
	};

	const reload = async () => {
		setError( null );
		try {
			setDoc( await request( PATH ) );
		} catch ( err ) {
			setError( err );
		}
	};

	const call = ( path, method, data = {} ) =>
		request( path, {
			method,
			data: { ...data, revision: doc.revision },
		} );

	// One-click actions on the list; errors show above the list.
	const run = async ( path, method, message, after ) => {
		setConfirm( null );
		setIsBusy( true );
		setError( null );
		try {
			setDoc( await call( path, method ) );
			after?.();
			notify.success( message );
		} catch ( err ) {
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	const saveRole = async ( data ) => {
		const isNew = editing === NEW;
		const saved = isNew
			? await call( PATH, 'POST', data )
			: await call( `${ PATH }/${ editing }`, 'PUT', data );
		setDoc( saved );
		setEditing( isNew ? saved.key : editing );
		setSaveCount( ( count ) => count + 1 );
		notify.success(
			isNew
				? __( 'Role added.', 'open-bulk-buying' )
				: __( 'Role saved.', 'open-bulk-buying' )
		);
	};

	// Actions on the role being edited would leave its panel showing old values.
	const closeIfEditing = ( key ) => () => {
		if ( key === editing ) {
			setEditing( null );
		}
	};
	const act = ( key, action ) =>
		key === editing ? guard( action ) : action();

	const open = ( key ) => guard( () => setEditing( key ) );
	const close = () => guard( () => setEditing( null ) );

	if ( loadError ) {
		return (
			<SettingsPage nav={ nav }>
				<ErrorNotice error={ loadError } />
			</SettingsPage>
		);
	}
	if ( ! doc ) {
		return (
			<SettingsPage nav={ nav }>
				<Loading />
			</SettingsPage>
		);
	}

	const editedRole =
		editing && editing !== NEW
			? doc.roles.find( ( role ) => role.key === editing )
			: null;
	const panel =
		editing === NEW || editedRole ? (
			<RolePanel
				key={ `${ editing }-${ saveCount }` }
				role={ editedRole }
				roles={ doc }
				onSave={ saveRole }
				onReload={ reload }
				onClose={ close }
			/>
		) : null;

	const confirmations = {
		delete: ( role ) => ( {
			text: role.members
				? sprintf(
						/* translators: 1: role name, 2: number of members. */
						_n(
							'Delete “%1$s”? It will be removed from %2$d member.',
							'Delete “%1$s”? It will be removed from %2$d members.',
							role.members,
							'open-bulk-buying'
						),
						role.label,
						role.members
				  )
				: sprintf(
						/* translators: %s: role name. */
						__( 'Delete “%s”?', 'open-bulk-buying' ),
						role.label
				  ),
			button: __( 'Delete', 'open-bulk-buying' ),
			onConfirm: () =>
				run(
					`${ PATH }/${ role.key }`,
					'DELETE',
					__( 'Role deleted.', 'open-bulk-buying' ),
					closeIfEditing( role.key )
				),
		} ),
		reset: ( role ) => ( {
			text: sprintf(
				/* translators: %s: role name. */
				__( 'Restore the original settings for “%s”?', 'open-bulk-buying' ),
				role.label
			),
			button: __( 'Reset', 'open-bulk-buying' ),
			onConfirm: () =>
				run(
					`${ PATH }/${ role.key }/reset`,
					'POST',
					__( 'Role reset.', 'open-bulk-buying' ),
					closeIfEditing( role.key )
				),
		} ),
		resetAll: () => ( {
			text: __(
				'Restore the original team roles? Custom roles will be deleted.',
				'open-bulk-buying'
			),
			button: __( 'Restore', 'open-bulk-buying' ),
			onConfirm: () =>
				run(
					`${ PATH }/reset-all`,
					'POST',
					__( 'Roles reset.', 'open-bulk-buying' ),
					() => setEditing( null )
				),
		} ),
	};
	const dialog = confirm && confirmations[ confirm.type ]( confirm.role );

	return (
		<SettingsPage nav={ nav } sidebar={ panel } sidebarSize="wide">
			<VStack spacing={ 6 } className="openbb-settings">
				<Section
					title={ __( 'Team roles', 'open-bulk-buying' ) }
					actions={
						<Button
							__next40pxDefaultSize
							variant="primary"
							icon={ plus }
							onClick={ () => open( NEW ) }
						>
							{ __( 'Add role', 'open-bulk-buying' ) }
						</Button>
					}
				>
					<Text as="p">
						{ __(
							'Give members access to the co-op’s admin screens. All active members can order without a role.',
							'open-bulk-buying'
						) }
					</Text>
					{ error?.code === STALE ? (
						<Notice
							status="error"
							isDismissible={ false }
							actions={ [
								{
									label: __( 'Reload roles', 'open-bulk-buying' ),
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
					<ul className="openbb-roles">
						{ doc.roles.map( ( role ) => (
							<li
								key={ role.key }
								className={
									'openbb-roles__item' +
									( role.key === editing ? ' is-selected' : '' )
								}
							>
								<div className="openbb-profile-fields__summary">
									<Button
										variant="link"
										onClick={ () => open( role.key ) }
									>
										{ role.label }
									</Button>{ ' ' }
									<Badges role={ role } />
									<div className="openbb-muted">
										{ memberCount( role ) }
									</div>
								</div>
								{ role.editable && (
									<HStack
										justify="flex-end"
										spacing={ 1 }
										className="openbb-profile-fields__actions"
									>
										{ role.modified && (
											<Button
												size="compact"
												variant="tertiary"
												disabled={ isBusy }
												accessibleWhenDisabled
												onClick={ () =>
													act( role.key, () =>
														setConfirm( {
															type: 'reset',
															role,
														} )
													)
												}
											>
												{ __( 'Reset', 'open-bulk-buying' ) }
											</Button>
										) }
										<Button
											size="compact"
											variant="tertiary"
											isDestructive
											disabled={ isBusy }
											accessibleWhenDisabled
											onClick={ () =>
												act( role.key, () =>
													setConfirm( {
														type: 'delete',
														role,
													} )
												)
											}
										>
											{ __( 'Delete…', 'open-bulk-buying' ) }
										</Button>
									</HStack>
								) }
							</li>
						) ) }
					</ul>
				</Section>

				{ doc.deleted.length > 0 && (
					<Section title={ __( 'Deleted roles', 'open-bulk-buying' ) }>
						<ul className="openbb-roles">
							{ doc.deleted.map( ( role ) => (
								<li key={ role.key } className="openbb-roles__item">
									<span>{ role.label }</span>
									<Button
										size="compact"
										variant="tertiary"
										disabled={ isBusy }
										accessibleWhenDisabled
										onClick={ () =>
											run(
												`${ PATH }/${ role.key }/reset`,
												'POST',
												__( 'Role restored.', 'open-bulk-buying' )
											)
										}
									>
										{ __( 'Restore', 'open-bulk-buying' ) }
									</Button>
								</li>
							) ) }
						</ul>
					</Section>
				) }

				{ ! doc.is_default && (
					<Section title={ __( 'Start over', 'open-bulk-buying' ) }>
						<div>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								isDestructive
								disabled={ isBusy }
								accessibleWhenDisabled
								onClick={ () =>
									guard( () => {
										setEditing( null );
										setConfirm( { type: 'resetAll' } );
									} )
								}
							>
								{ __( 'Restore original roles…', 'open-bulk-buying' ) }
							</Button>
						</div>
					</Section>
				) }
			</VStack>
			<ConfirmDialog
				isOpen={ !! dialog }
				confirmButtonText={ dialog?.button }
				onConfirm={ () => dialog.onConfirm() }
				onCancel={ () => setConfirm( null ) }
			>
				{ dialog?.text }
			</ConfirmDialog>
			{ discardDialog }
		</SettingsPage>
	);
}
