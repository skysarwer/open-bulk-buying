import { Fragment, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { EditForm, ErrorNotice, RecordLink, SidePanel } from '../../components';
import { useRecord } from '../../hooks';
import { request } from '../../utils/api';
import { can, config } from '../../utils/config';
import { formatMoney } from '../../utils/format';
import { RolesEdit, formatDate, memberFields, memberForm } from './fields';
import MembershipFee from './membership-fee';
import {
	PROFILE_PREFIX,
	fieldLabel,
	profileEdits,
	profileFormFields,
	profileValueText,
	useProfileFields,
	withFieldErrors,
} from './profile';

const GROUP_LAYOUT = { type: 'regular', labelPosition: 'top' };
const DETAIL_KEYS = [ 'first_name', 'last_name', 'phone', 'language' ];

/**
 * What the current user may change about this member.
 *
 * @param {Object} member
 */
const permissions = ( member ) => ( {
	details: can( 'openbb_edit_members' ) && member.can_edit,
	status: member.status_options.length > 1,
	roles: member.assignable_roles.length > 0,
} );

function MemberDetailsForm( { member, onSaved, onCancel } ) {
	const { fields: defs, error: defsError } = useProfileFields();
	const allowed = permissions( member );

	const fields = useMemo(
		() => [
			...memberFields.flatMap( ( field ) => {
				if ( field.id === 'status' ) {
					return allowed.status
						? [ { ...field, elements: field.elements.filter( ( el ) => member.status_options.includes( el.value ) ) } ]
						: [];
				}
				if ( field.id === 'roles' ) {
					return allowed.roles
						? [ { ...field, Edit: ( props ) => <RolesEdit { ...props } assignable={ member.assignable_roles } /> } ]
						: [];
				}
				return allowed.details || ! DETAIL_KEYS.includes( field.id ) ? [ field ] : [];
			} ),
			...( allowed.details ? profileFormFields( defs || [], 'staff' ) : [] ),
		],
		[ member, defs ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const form = useMemo( () => {
		const ids = ( list ) => list.map( ( def ) => PROFILE_PREFIX + def.key );
		const active = ( defs || [] ).filter( ( def ) => ! def.archived );
		const archived = ( defs || [] ).filter( ( def ) => def.archived );
		const group = ( id, label, list ) =>
			allowed.details && list.length ? [ { id, label, layout: GROUP_LAYOUT, children: ids( list ) } ] : [];
		const shown = new Set( fields.map( ( field ) => field.id ) );
		return {
			...memberForm,
			fields: [
				...memberForm.fields.filter( ( entry ) =>
					typeof entry === 'string' ? shown.has( entry ) : allowed.details
				),
				...group( 'profile', __( 'Profile fields', 'open-bulk-buying' ), active ),
				...group( 'profile-archived', __( 'Archived fields', 'open-bulk-buying' ), archived ),
			],
		};
	}, [ defs, fields ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<EditForm
			fields={ fields }
			form={ form }
			initial={ member }
			requireChanges
			onCancel={ onCancel }
			onSubmit={ async ( data, edits ) => {
				const keys = [ ...DETAIL_KEYS, 'status', 'roles' ];
				const payload = Object.fromEntries(
					keys.filter( ( key ) => key in edits ).map( ( key ) => [ key, data[ key ] ?? '' ] )
				);
				const profile = profileEdits( data, edits );
				if ( Object.keys( profile ).length ) {
					payload.profile = profile;
				}
				try {
					onSaved(
						await request( `/members/${ member.id }`, { method: 'PUT', data: payload } )
					);
				} catch ( err ) {
					throw withFieldErrors( err, defs );
				}
			} }
		>
			<ErrorNotice error={ defsError } />
			<MemberFacts member={ member } showStatus={ ! allowed.status } showRoles={ ! allowed.roles } />
		</EditForm>
	);
}

function MemberFacts( { member, showStatus = true, showRoles = true } ) {
	return (
		<dl className="openbb-details">
			{ showStatus && (
				<>
					<dt>{ __( 'Status', 'open-bulk-buying' ) }</dt>
					<dd>{ member.status_label }</dd>
				</>
			) }
			{ showRoles && (
				<>
					<dt>{ __( 'Team roles', 'open-bulk-buying' ) }</dt>
					<dd>{ member.role_labels.join( ', ' ) || '—' }</dd>
				</>
			) }
			<dt>{ __( 'Email', 'open-bulk-buying' ) }</dt>
			<dd>
				<a href={ `mailto:${ member.email }` }>{ member.email }</a>
			</dd>
			<dt>{ __( 'Joined', 'open-bulk-buying' ) }</dt>
			<dd>{ formatDate( member.joined_at ) }</dd>
			<dt>{ __( 'Rules', 'open-bulk-buying' ) }</dt>
			<dd>
				{ member.rules_accepted
					? __( 'Accepted', 'open-bulk-buying' )
					: __( 'Not accepted', 'open-bulk-buying' ) }
			</dd>
			{ member.profile_url && (
				<>
					<dt>{ __( 'Profile', 'open-bulk-buying' ) }</dt>
					<dd>
						<a href={ member.profile_url }>
							{ __( 'Edit WordPress profile', 'open-bulk-buying' ) }
						</a>
					</dd>
				</>
			) }
		</dl>
	);
}

function MemberReadOnly( { member } ) {
	const { fields: defs, error } = useProfileFields();
	// Archived fields only when the member has a value for them.
	const shown = ( defs || [] ).filter( ( def ) => {
		if ( ! def.archived ) {
			return true;
		}
		const value = member.profile?.[ def.key ];
		return Array.isArray( value ) ? value.length > 0 : !! value;
	} );

	return (
		<>
			<ErrorNotice error={ error } />
			<MemberFacts member={ member } />
			<dl className="openbb-details">
				<dt>{ __( 'Phone', 'open-bulk-buying' ) }</dt>
				<dd>{ member.phone || '—' }</dd>
				{ shown.map( ( def ) => (
					<Fragment key={ def.key }>
						<dt>
							{ fieldLabel( def ) }
							{ def.archived && (
								<span className="openbb-muted"> { __( '(archived)', 'open-bulk-buying' ) }</span>
							) }
						</dt>
						<dd>{ profileValueText( def, member.profile?.[ def.key ] ) }</dd>
					</Fragment>
				) ) }
			</dl>
		</>
	);
}

function MemberOrders( { orders } ) {
	if ( ! orders.length ) {
		return <p>{ __( 'No orders yet.', 'open-bulk-buying' ) }</p>;
	}
	return (
		<table className="widefat striped openbb-compact-table">
			<thead>
				<tr>
					<th>{ __( 'Order', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Cycle', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Status', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Total', 'open-bulk-buying' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ orders.map( ( order ) => (
					<tr key={ order.id }>
						<td>
							<RecordLink
								type="order"
								id={ order.id }
								href={ `${ config.urls.orders }&order=${ order.id }` }
							>
								#{ order.id }
							</RecordLink>
						</td>
						<td>{ order.cycle_name }</td>
						<td>{ order.status_label }</td>
						<td>{ formatMoney( order.total ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * A member's details (editable when allowed) and order history.
 *
 * @param {Object} props
 */
export default function MemberPanel( { memberId, onClose, onSaved } ) {
	const { record: member, setRecord, error, isLoading } = useRecord(
		`/members/${ memberId }`
	);

	const tabs = [
		{ name: 'details', title: __( 'Details', 'open-bulk-buying' ) },
		...( can( 'openbb_view_orders' )
			? [ { name: 'orders', title: __( 'Orders', 'open-bulk-buying' ) } ]
			: [] ),
	];

	return (
		<SidePanel
			title={ member?.display_name || __( 'Member', 'open-bulk-buying' ) }
			onClose={ onClose }
			error={ error }
			isLoading={ isLoading }
			tabs={ tabs }
		>
			{ ( tab ) => {
				if ( tab.name === 'orders' ) {
					return <MemberOrders orders={ member.orders || [] } />;
				}
				const saved = ( item, message ) => {
					// The update response has no order history; keep the loaded one.
					setRecord( ( prev ) => ( { ...prev, ...item } ) );
					onSaved( item, message );
				};
				const fee = <MembershipFee member={ member } onSaved={ saved } />;
				if ( ! Object.values( permissions( member ) ).some( Boolean ) ) {
					return (
						<>
							<MemberReadOnly member={ member } />
							{ fee }
						</>
					);
				}
				return (
					<>
						<MemberDetailsForm member={ member } onCancel={ onClose } onSaved={ saved } />
						{ fee }
					</>
				);
			} }
		</SidePanel>
	);
}
