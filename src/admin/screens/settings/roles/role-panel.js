import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ErrorNotice, SidePanel } from '../../../components';
import { discardUnsavedChanges, useUnsavedChanges } from '../../../hooks';

export const STALE = 'openbb_roles_stale';
const LABEL_MAX = 60;

/**
 * Turns one permission on or off, keeping the set consistent: turning a permission
 * on also turns on what it requires, turning it off also turns off what requires it,
 * and children follow their parent.
 *
 * @param {string[]} caps
 * @param {string}   key
 * @param {boolean}  on
 * @param {Object[]} capabilities
 */
export function toggleCap( caps, key, on, capabilities ) {
	const next = new Set( caps );
	const visit = ( cap ) => {
		if ( on === next.has( cap ) ) {
			return;
		}
		if ( on ) {
			next.add( cap );
		} else {
			next.delete( cap );
		}
		capabilities.forEach( ( def ) => {
			if ( def.parent === cap ) {
				visit( def.key );
			}
			if ( on && def.key === cap ) {
				def.requires.forEach( visit );
			}
			if ( ! on && def.requires.includes( cap ) ) {
				visit( def.key );
			}
		} );
	};
	visit( key );
	return capabilities
		.map( ( def ) => def.key )
		.filter( ( cap ) => next.has( cap ) );
}

/**
 * Creates or edits one role.
 *
 * @param {Object}      props
 * @param {Object|null} props.role     The role, or null for a new one.
 * @param {Object}      props.roles    The loaded roles document.
 * @param {Function}    props.onSave   ( { label, caps } ) => Promise.
 * @param {Function}    props.onReload Reloads the roles after a 409.
 * @param {Function}    props.onClose
 */
export default function RolePanel( { role, roles, onSave, onReload, onClose } ) {
	const isNew = ! role;
	const initial = useMemo(
		() => ( { label: role?.label || '', caps: role?.caps || [] } ),
		[] // eslint-disable-line react-hooks/exhaustive-deps
	);
	const [ draft, setDraft ] = useState( initial );
	const [ error, setError ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	useUnsavedChanges( isDirty );

	const readOnly = !! role && ! role.editable;
	const isDisabled = ( def ) =>
		readOnly || ( ! roles.is_admin && ! def.held );

	const submit = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			await onSave( { label: draft.label.trim(), caps: draft.caps } );
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	const cancel = () => {
		discardUnsavedChanges();
		onClose();
	};

	const title = isNew
		? __( 'New team role', 'open-bulk-buying' )
		: draft.label || role.label;

	return (
		<SidePanel title={ title } onClose={ onClose }>
			<form onSubmit={ submit } className="openbb-role-editor">
				<VStack spacing={ 4 }>
					{ error?.code === STALE ? (
						<Notice
							status="error"
							isDismissible={ false }
							actions={ [
								{
									label: __( 'Reload roles', 'open-bulk-buying' ),
									onClick: async () => {
										await onReload();
										setError( null );
									},
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
					{ readOnly && (
						<Notice status="info" isDismissible={ false }>
							{ __( 'You can’t edit this role.', 'open-bulk-buying' ) }
						</Notice>
					) }

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'open-bulk-buying' ) }
						value={ draft.label }
						maxLength={ LABEL_MAX }
						disabled={ readOnly }
						onChange={ ( label ) =>
							setDraft( ( prev ) => ( { ...prev, label } ) )
						}
					/>

					{ roles.groups.map( ( group ) => {
						const caps = roles.capabilities.filter(
							( def ) => def.group === group.key
						);
						if ( ! caps.length ) {
							return null;
						}
						return (
							<fieldset
								key={ group.key }
								className="openbb-role-editor__group"
							>
								<legend className="openbb-section-title">
									{ group.label }
								</legend>
								<VStack spacing={ 2 }>
									{ caps.map( ( def ) => (
										<CheckboxControl
											__nextHasNoMarginBottom
											key={ def.key }
											id={ `openbb-cap-${ def.key }` }
											className={
												def.parent
													? 'openbb-role-editor__child'
													: undefined
											}
											label={ def.label }
											help={ def.description }
											checked={ draft.caps.includes(
												def.key
											) }
											disabled={ isDisabled( def ) }
											onChange={ ( on ) =>
												setDraft( ( prev ) => ( {
													...prev,
													caps: toggleCap(
														prev.caps,
														def.key,
														on,
														roles.capabilities
													),
												} ) )
											}
										/>
									) ) }
								</VStack>
							</fieldset>
						);
					} ) }

					<HStack justify="flex-end" className="openbb-form-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ cancel }
							disabled={ isBusy }
						>
							{ readOnly
								? __( 'Close', 'open-bulk-buying' )
								: __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						{ ! readOnly && (
							<Button
								__next40pxDefaultSize
								variant="primary"
								type="submit"
								isBusy={ isBusy }
								disabled={
									isBusy ||
									! draft.label.trim() ||
									( ! isNew && ! isDirty )
								}
								accessibleWhenDisabled
							>
								{ isNew
									? __( 'Add role', 'open-bulk-buying' )
									: __( 'Save', 'open-bulk-buying' ) }
							</Button>
						) }
					</HStack>
				</VStack>
			</form>
		</SidePanel>
	);
}
