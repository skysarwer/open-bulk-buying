import { CheckboxControl, __experimentalVStack as VStack } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';

import { config, languageLabel, toElements } from '../../utils/config';

export const formatDate = ( value ) =>
	value ? dateI18n( getSettings().formats.date, value ) : '—';

// A function, as the Team roles settings tab can change the roles without a page load.
export const roleElements = () => toElements( config.coopRoles );

/**
 * Role checkboxes; only roles in `assignable` can be ticked or unticked.
 *
 * @param {Object}   props
 * @param {string[]} props.assignable
 */
export function RolesEdit( { data, field, onChange, assignable } ) {
	const value = field.getValue( { item: data } ) || [];
	return (
		<fieldset className="openbb-roles-edit">
			<legend>{ field.label }</legend>
			<VStack spacing={ 2 }>
				{ roleElements().map( ( role ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ role.value }
						id={ `openbb-role-${ role.value }` }
						label={ role.label }
						checked={ value.includes( role.value ) }
						disabled={ ! assignable.includes( role.value ) }
						onChange={ ( checked ) =>
							onChange(
								field.setValue( {
									item: data,
									value: checked
										? [ ...value, role.value ]
										: value.filter( ( key ) => key !== role.value ),
								} )
							)
						}
					/>
				) ) }
			</VStack>
			<p className="description">
				{ __( 'Gives access to admin screens. Only applies while the member is active.', 'open-bulk-buying' ) }
			</p>
		</fieldset>
	);
}

const languageElements = config.languages.map( ( lang ) => ( {
	value: lang,
	label: languageLabel( lang ),
} ) );

export const memberFields = [
	{
		id: 'display_name',
		label: __( 'Name', 'open-bulk-buying' ),
		type: 'text',
		enableHiding: false,
		filterBy: false,
	},
	{
		id: 'email',
		label: __( 'Email', 'open-bulk-buying' ),
		type: 'email',
		filterBy: false,
	},
	{
		id: 'first_name',
		label: __( 'First name', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'last_name',
		label: __( 'Last name', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'phone',
		label: __( 'Phone', 'open-bulk-buying' ),
		type: 'telephone',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'language',
		label: __( 'Language', 'open-bulk-buying' ),
		type: 'text',
		...( languageElements.length
			? {
					Edit: 'select',
					elements: [
						{ value: '', label: __( 'Site default', 'open-bulk-buying' ) },
						...languageElements,
					],
			  }
			: {} ),
		render: ( { item } ) => ( item.language ? languageLabel( item.language ) : '' ),
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'status',
		label: __( 'Status', 'open-bulk-buying' ),
		type: 'text',
		Edit: 'select',
		elements: toElements( config.memberStatuses ),
		render: ( { item } ) => (
			<span className={ `openbb-badge openbb-badge--status-${ item.status }` }>
				{ item.status_label }
			</span>
		),
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
		enableSorting: false,
	},
	{
		id: 'roles',
		label: __( 'Team roles', 'open-bulk-buying' ),
		type: 'array',
		get elements() {
			return roleElements();
		},
		render: ( { item } ) => item.role_labels?.join( ', ' ) || '',
		filterBy: { operators: [ 'isAny' ] },
		enableSorting: false,
	},
	{
		id: 'orders_count',
		label: __( 'Orders', 'open-bulk-buying' ),
		type: 'integer',
		readOnly: true,
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'last_order_at',
		label: __( 'Last order', 'open-bulk-buying' ),
		type: 'datetime',
		readOnly: true,
		render: ( { item } ) => formatDate( item.last_order_at ),
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'joined_at',
		label: __( 'Joined', 'open-bulk-buying' ),
		type: 'datetime',
		readOnly: true,
		render: ( { item } ) => formatDate( item.joined_at ),
		filterBy: false,
	},
	{
		id: 'rules_accepted',
		label: __( 'Rules', 'open-bulk-buying' ),
		type: 'boolean',
		readOnly: true,
		render: ( { item } ) =>
			item.rules_accepted
				? __( 'Accepted', 'open-bulk-buying' )
				: __( 'Not accepted', 'open-bulk-buying' ),
		enableSorting: false,
		filterBy: false,
	},
];

export const memberForm = {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [
		{
			id: 'names',
			label: __( 'Name', 'open-bulk-buying' ),
			layout: { type: 'row' },
			children: [ 'first_name', 'last_name' ],
		},
		'phone',
		'language',
		'status',
		'roles',
	],
};
