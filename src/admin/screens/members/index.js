import {
	Button,
	ComboboxControl,
	SelectControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { pencil, plus, seen } from '@wordpress/icons';
import { DataForm, DataViews, useFormValidity } from '@wordpress/dataviews/wp';

import { ErrorNotice, Page, SidePanel, usePanelStack } from '../../components';
import {
	discardUnsavedChanges,
	useDebouncedValue,
	useDiscardGuard,
	useList,
	useNotify,
	useUnsavedChanges,
} from '../../hooks';
import { request } from '../../utils/api';
import { can, config, queryArg, toElements } from '../../utils/config';
import relatedPanels from '../related-panels';
import { memberFields } from './fields';
import MemberPanel from './panel';
import { profileEdits, profileFormFields, useProfileFields, withFieldErrors } from './profile';

const LIST_MAPPING = {
	orderby: { display_name: 'name', email: 'email', joined_at: 'registered' },
	filters: {
		coop_role: ( filter ) => ( { coop_role: filter.value } ),
	},
};

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'display_name', direction: 'asc' },
	titleField: 'display_name',
	descriptionField: 'email',
	fields: [ 'coop_role', 'phone', 'orders_count', 'last_order_at', 'rules_accepted' ],
	filters: [],
};

/**
 * Roles a new member may be given. Staff roles need openbb_manage_access; the
 * server checks again.
 */
const addableRoles = () =>
	toElements( config.coopRoles ).filter(
		( role ) =>
			can( 'openbb_manage_access' ) || ! config.staffRoles.includes( role.value )
	);

function AddMemberPanel( { onClose, onAdded } ) {
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ userId, setUserId ] = useState( null );
	const [ role, setRole ] = useState( 'member' );
	const [ profile, setProfile ] = useState( {} );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const debounced = useDebouncedValue( search );
	const { fields: defs, error: defsError } = useProfileFields();
	useUnsavedChanges( !! userId || Object.keys( profile ).length > 0 );

	// Only the required fields; the rest can be filled in afterwards.
	const profileFields = useMemo(
		() =>
			profileFormFields(
				( defs || [] ).filter( ( def ) => def.required && ! def.archived ),
				'staff_create'
			),
		[ defs ]
	);
	const profileForm = useMemo(
		() => ( {
			layout: { type: 'regular', labelPosition: 'top' },
			fields: profileFields.map( ( field ) => field.id ),
		} ),
		[ profileFields ]
	);
	const { validity, isValid } = useFormValidity( profile, profileFields, profileForm );

	useEffect( () => {
		let current = true;
		request( '/members/candidates', { query: { search: debounced } } )
			.then( ( users ) => {
				if ( ! current ) {
					return;
				}
				setOptions( ( prev ) => {
					// Keep the chosen user listed so the combobox can show it.
					const selected = prev.find( ( option ) => option.value === userId );
					const next = users.map( ( user ) => ( {
						value: user.id,
						label: `${ user.name } (${ user.email })`,
					} ) );
					return selected && ! next.some( ( o ) => o.value === userId )
						? [ selected, ...next ]
						: next;
				} );
			} )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ debounced ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const submit = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onAdded(
				await request( '/members', {
					method: 'POST',
					data: { user_id: userId, coop_role: role, profile: profileEdits( profile, profile ) },
				} )
			);
		} catch ( err ) {
			setError( withFieldErrors( err, defs ) );
			setIsBusy( false );
		}
	};

	return (
		<SidePanel title={ __( 'Add member', 'open-bulk-buying' ) } onClose={ onClose }>
			<form onSubmit={ submit } className="openbb-edit-form">
				<VStack spacing={ 4 }>
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
					<ComboboxControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'User', 'open-bulk-buying' ) }
						help={ __(
							'Search the site’s users who are not members yet, by name or email.',
							'open-bulk-buying'
						) }
						value={ userId }
						options={ options }
						onChange={ setUserId }
						onFilterValueChange={ setSearch }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Role', 'open-bulk-buying' ) }
						value={ role }
						options={ addableRoles() }
						onChange={ setRole }
					/>
					<ErrorNotice error={ defsError } />
					{ profileFields.length > 0 && (
						<DataForm
							data={ profile }
							fields={ profileFields }
							form={ profileForm }
							validity={ validity }
							onChange={ ( changes ) => setProfile( ( prev ) => ( { ...prev, ...changes } ) ) }
						/>
					) }
					<HStack justify="flex-end" className="openbb-form-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => {
								discardUnsavedChanges();
								onClose();
							} }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ! userId || ! isValid || ! defs }
							accessibleWhenDisabled
						>
							{ __( 'Add member', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</SidePanel>
	);
}

export default function MembersScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	// A member id, or 0 to add a member.
	const [ panel, setPanel ] = useState( () => {
		const id = Number( queryArg( 'member' ) );
		return id || null;
	} );
	const list = useList( '/members', view, LIST_MAPPING );
	const notify = useNotify();
	const canEdit = can( 'openbb_edit_members' );
	const [ guard, discardDialog ] = useDiscardGuard();

	const closePanel = () =>
		guard( () => {
			setPanel( null );
			nav.reset();
		} );
	const nav = usePanelStack( {
		panels: relatedPanels,
		guard,
		base: panel ? { type: 'member', id: panel } : null,
		onClose: closePanel,
		onSaved: () => list.reload(),
	} );

	const openPanel = useCallback(
		( id ) => {
			if ( id !== panel || nav.top ) {
				guard( () => {
					setPanel( id );
					nav.reset();
				} );
			}
		},
		[ panel, guard, nav.top, nav.reset ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const actions = useMemo(
		() => [
			{
				id: 'open',
				label: canEdit ? __( 'Edit', 'open-bulk-buying' ) : __( 'View', 'open-bulk-buying' ),
				icon: canEdit ? pencil : seen,
				isPrimary: true,
				callback: ( [ item ] ) => openPanel( item.id ),
			},
		],
		[ canEdit, openPanel ]
	);

	let sidebar = null;
	if ( panel === 0 ) {
		sidebar = (
			<AddMemberPanel
				onClose={ closePanel }
				onAdded={ ( item ) => {
					setPanel( item.id );
					list.reload();
					notify.success(
						/* translators: %s: member name. */
						sprintf( __( '%s is now a member.', 'open-bulk-buying' ), item.display_name )
					);
				} }
			/>
		);
	} else if ( panel ) {
		sidebar = (
			<MemberPanel
				key={ panel }
				memberId={ panel }
				onClose={ closePanel }
				onSaved={ ( item ) => {
					if ( item.removed ) {
						setPanel( null );
						list.reload();
					} else {
						list.replaceItem( item );
					}
					notify.success( __( 'Member saved.', 'open-bulk-buying' ) );
				} }
			/>
		);
	}

	return (
		<Page
			title={ __( 'Members', 'open-bulk-buying' ) }
			sidebar={ nav.render( sidebar ) }
			sidebarSize={ nav.size || 'default' }
			actions={
				canEdit && (
					<Button variant="primary" icon={ plus } onClick={ () => openPanel( 0 ) }>
						{ __( 'Add member', 'open-bulk-buying' ) }
					</Button>
				)
			}
		>
			<ErrorNotice error={ list.error } />
			{ discardDialog }
			<DataViews
				data={ list.items }
				fields={ memberFields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ list.paginationInfo }
				isLoading={ list.isLoading }
				defaultLayouts={ { table: {}, list: {} } }
				getItemId={ ( item ) => String( item.id ) }
				onClickItem={ ( item ) => openPanel( item.id ) }
				isItemClickable={ () => true }
				searchLabel={ __( 'Search members', 'open-bulk-buying' ) }
			/>
		</Page>
	);
}
