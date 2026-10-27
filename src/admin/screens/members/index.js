import {
	Button,
	CheckboxControl,
	ComboboxControl,
	SelectControl,
	__experimentalHStack as HStack,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
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
import { RolesEdit, memberFields } from './fields';
import MemberPanel from './panel';
import {
	profileEdits,
	profileFormFields,
	profileListFields,
	profileListFilters,
	useProfileFields,
	withFieldErrors,
} from './profile';

const LIST_MAPPING = {
	orderby: { display_name: 'name', email: 'email', joined_at: 'registered' },
	filters: {
		status: ( filter ) => ( { status: filter.value } ),
		roles: ( filter ) => ( { role: filter.value } ),
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
	fields: [ 'status', 'roles', 'phone', 'orders_count', 'last_order_at', 'rules_accepted' ],
	filters: [],
};

// New members start as applicants or active members.
const addableStatuses = () =>
	toElements( config.memberStatuses ).filter( ( status ) =>
		[ 'active', 'applicant' ].includes( status.value )
	);

const ACCOUNT_KEYS = [ 'email', 'first_name', 'last_name', 'phone', 'language' ];
const REQUIRED_ACCOUNT_KEYS = [ 'email', 'first_name' ];

const accountFields = memberFields
	.filter( ( field ) => ACCOUNT_KEYS.includes( field.id ) )
	.map( ( field ) =>
		REQUIRED_ACCOUNT_KEYS.includes( field.id ) ? { ...field, isValid: { required: true } } : field
	);

const accountLabels = Object.fromEntries( accountFields.map( ( field ) => [ field.id, field.label ] ) );

const accountForm = {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [
		'email',
		{
			id: 'names',
			label: __( 'Name', 'open-bulk-buying' ),
			layout: { type: 'row' },
			children: [ 'first_name', 'last_name' ],
		},
		'phone',
		'language',
	],
};

// RolesEdit outside a DataForm.
const rolesField = {
	label: __( 'Team roles', 'open-bulk-buying' ),
	getValue: ( { item } ) => item.roles,
	setValue: ( { value } ) => ( { roles: value } ),
};

// Reserved TLD (RFC 2606) for members without an email address; mail is never sent there.
const isPlaceholderEmail = ( email ) => /\.invalid$/i.test( ( email || '' ).trim() );

function AddMemberPanel( { onClose, onAdded } ) {
	const [ mode, setMode ] = useState( 'existing' );
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ userId, setUserId ] = useState( null );
	const [ account, setAccount ] = useState( {} );
	const [ sendEmail, setSendEmail ] = useState( true );
	const [ status, setStatus ] = useState( 'active' );
	const [ roles, setRoles ] = useState( [] );
	const [ profile, setProfile ] = useState( {} );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const debounced = useDebouncedValue( search );
	const { fields: defs, error: defsError } = useProfileFields();
	const isNew = 'new' === mode;
	const placeholder = isPlaceholderEmail( account.email );
	useUnsavedChanges(
		( isNew ? Object.values( account ).some( Boolean ) : !! userId ) || Object.keys( profile ).length > 0
	);

	const profileFields = useMemo(
		() => profileFormFields( ( defs || [] ).filter( ( def ) => ! def.archived ), 'staff_create' ),
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
	const { validity: accountValidity, isValid: isAccountValid } = useFormValidity( account, accountFields, accountForm );

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
			const data = { status, profile: profileEdits( profile, profile ) };
			if ( roles.length ) {
				data.roles = roles;
			}
			if ( isNew ) {
				data.account = Object.fromEntries( ACCOUNT_KEYS.map( ( key ) => [ key, ( account[ key ] || '' ).trim() ] ) );
				data.send_email = sendEmail && ! placeholder;
			} else {
				data.user_id = userId;
			}
			onAdded( await request( '/members', { method: 'POST', data } ) );
		} catch ( err ) {
			setError( withFieldErrors( err, defs, accountLabels ) );
			setIsBusy( false );
		}
	};

	return (
		<SidePanel title={ __( 'Add member', 'open-bulk-buying' ) } onClose={ onClose }>
			<form onSubmit={ submit } className="openbb-edit-form">
				<VStack spacing={ 4 }>
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Who', 'open-bulk-buying' ) }
						hideLabelFromVision
						value={ mode }
						onChange={ ( value ) => value && setMode( value ) }
					>
						<ToggleGroupControlOption value="existing" label={ __( 'Existing user', 'open-bulk-buying' ) } />
						<ToggleGroupControlOption value="new" label={ __( 'New user', 'open-bulk-buying' ) } />
					</ToggleGroupControl>
					{ isNew ? (
						<>
							<DataForm
								data={ account }
								fields={ accountFields }
								form={ accountForm }
								validity={ accountValidity }
								onChange={ ( changes ) => setAccount( ( prev ) => ( { ...prev, ...changes } ) ) }
							/>
							<p className="description">
								{ __(
									'No email address? Use a made-up one ending in .invalid, such as jane.doe.4821@noemail.invalid. It can be replaced later.',
									'open-bulk-buying'
								) }
							</p>
							<CheckboxControl
								__nextHasNoMarginBottom
								id="openbb-add-member-send-email"
								label={ __( 'Send account email', 'open-bulk-buying' ) }
								help={
									placeholder
										? __( 'Placeholder addresses can’t receive email.', 'open-bulk-buying' )
										: __(
												'Emails them a link to set their password. Without it, they can use “Lost your password?” on the login page later.',
												'open-bulk-buying'
										  )
								}
								checked={ sendEmail && ! placeholder }
								disabled={ placeholder }
								onChange={ setSendEmail }
							/>
						</>
					) : (
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
					) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'open-bulk-buying' ) }
						value={ status }
						options={ addableStatuses() }
						onChange={ setStatus }
					/>
					{ can( 'openbb_manage_access' ) && (
						<RolesEdit
							data={ { roles } }
							field={ rolesField }
							assignable={ Object.keys( config.coopRoles ) }
							onChange={ ( next ) => setRoles( next.roles ) }
						/>
					) }
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
							disabled={ isBusy || ! ( isNew ? isAccountValid : userId ) || ! isValid || ! defs }
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
	const { fields: profileDefs } = useProfileFields();
	const [ fields, mapping ] = useMemo(
		() => [
			[ ...memberFields, ...profileListFields( profileDefs ) ],
			{
				...LIST_MAPPING,
				filters: { ...LIST_MAPPING.filters, ...profileListFilters( profileDefs ) },
			},
		],
		[ profileDefs ]
	);
	const list = useList( '/members', view, mapping );
	const notify = useNotify();
	const canEdit = can( 'openbb_edit_members' );
	const canCreate = can( 'openbb_create_members' );
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
						item.email_sent
							? /* translators: %s: member name. */
							  sprintf( __( '%s is now a member. They’ve been emailed a link to set their password.', 'open-bulk-buying' ), item.display_name )
							: /* translators: %s: member name. */
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
				onSaved={ ( item, message ) => {
					if ( item.removed ) {
						setPanel( null );
						list.reload();
					} else {
						list.replaceItem( item );
					}
					notify.success( message || __( 'Member saved.', 'open-bulk-buying' ) );
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
				canCreate && (
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
				fields={ fields }
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
