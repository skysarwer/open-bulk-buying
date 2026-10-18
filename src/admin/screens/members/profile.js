import { Button, SelectControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { request } from '../../utils/api';
import { config } from '../../utils/config';
import { localized } from '../settings/profile-fields/utils';

/** Form data keeps edited profile values flat, as `profile__{key}`. */
export const PROFILE_PREFIX = 'profile__';

// The admin's language, when field labels have a translation for it.
const LANG = ( () => {
	const code = ( config.locale || '' ).split( /[-_]/ )[ 0 ].toLowerCase();
	return config.translationLanguages?.includes( code ) ? code : '';
} )();

export const fieldLabel = ( def ) => localized( def.label, LANG ) || def.key;

let cache = null;

/**
 * Profile field definitions for the Members screen, including archived ones,
 * loaded once per page.
 *
 * @return {{fields: Array|null, error: Object|null}} State.
 */
export function useProfileFields() {
	const [ state, setState ] = useState( () => ( {
		fields: cache?.fields || null,
		error: null,
	} ) );

	useEffect( () => {
		if ( cache?.fields ) {
			return;
		}
		let current = true;
		if ( ! cache ) {
			cache = { promise: request( '/profile-fields' ) };
		}
		const { promise } = cache;
		promise
			.then( ( fields ) => {
				cache.fields = fields;
				if ( current ) {
					setState( { fields, error: null } );
				}
			} )
			.catch( ( error ) => {
				cache = null;
				if ( current ) {
					setState( { fields: [], error } );
				}
			} );
		return () => {
			current = false;
		};
	}, [] );

	return state;
}

const emptyValue = ( def ) => {
	if ( def.type === 'boolean' ) {
		return false;
	}
	return def.type === 'options' ? [] : '';
};

const valueOf = ( item, def ) => {
	const id = PROFILE_PREFIX + def.key;
	const value = id in item ? item[ id ] : item.profile?.[ def.key ];
	return value ?? emptyValue( def );
};

const allOptions = ( def ) =>
	( def.options?.groups || [] ).flatMap( ( group ) => group.options );

/**
 * An option's label; archived options say so and unknown keys show as is.
 *
 * @param {Object} def
 * @param {string} key
 */
export function optionLabel( def, key ) {
	const option = allOptions( def ).find( ( o ) => o.key === key );
	if ( ! option ) {
		return key;
	}
	const label = localized( option.label, LANG ) || key;
	return option.archived
		? /* translators: %s: option label. */
		  sprintf( __( '%s (archived)', 'open-bulk-buying' ), label )
		: label;
}

/**
 * A value as text, for read-only display.
 *
 * @param {Object} def
 * @param {*}      value
 */
export function profileValueText( def, value ) {
	if ( def.type === 'boolean' ) {
		return value
			? __( 'Yes', 'open-bulk-buying' )
			: __( 'No', 'open-bulk-buying' );
	}
	if ( def.type === 'options' ) {
		return (
			( value || [] )
				.map( ( key ) => optionLabel( def, key ) )
				.join( ', ' ) || '—'
		);
	}
	return value || '—';
}

/**
 * Members list columns for profile fields; hidden until picked in the view
 * options. True/false and options fields can be filtered on.
 *
 * @param {Array} defs Field definitions.
 * @return {Array} DataViews fields.
 */
export function profileListFields( defs ) {
	return ( defs || [] ).map( ( def ) => {
		const field = {
			id: PROFILE_PREFIX + def.key,
			label: fieldLabel( def ),
			type: 'text',
			getValue: ( { item } ) => item.profile?.[ def.key ] ?? emptyValue( def ),
			render: ( { item } ) =>
				profileValueText( def, item.profile?.[ def.key ] ?? emptyValue( def ) ),
			enableSorting: false,
			filterBy: false,
		};
		if ( def.type === 'boolean' ) {
			field.elements = [
				{ value: 'true', label: __( 'Yes', 'open-bulk-buying' ) },
				{ value: 'false', label: __( 'No', 'open-bulk-buying' ) },
			];
			field.filterBy = { operators: [ 'is' ] };
		} else if ( def.type === 'options' ) {
			field.elements = allOptions( def ).map( ( option ) => ( {
				value: option.key,
				label: optionLabel( def, option.key ),
			} ) );
			field.filterBy = { operators: [ 'isAny' ] };
		}
		return field;
	} );
}

/**
 * Maps profile filters to `profile[key]` query args.
 *
 * @param {Array} defs Field definitions.
 * @return {Object} Field id => function( filter ) returning query args.
 */
export function profileListFilters( defs ) {
	return Object.fromEntries(
		( defs || [] )
			.filter( ( def ) => def.type === 'boolean' || def.type === 'options' )
			.map( ( def ) => [
				PROFILE_PREFIX + def.key,
				( filter ) => ( { [ `profile[${ def.key }]` ]: [].concat( filter.value ) } ),
			] )
	);
}

/**
 * Groups of the options to offer: active ones, plus archived or unknown keys
 * the member already holds, so they can be kept or removed.
 *
 * @param {Object} def
 * @param {Array}  held
 */
function offeredGroups( def, held ) {
	const known = new Set( allOptions( def ).map( ( o ) => o.key ) );
	const groups = ( def.options?.groups || [] )
		.map( ( group ) => ( {
			key: group.key,
			label: group.key ? localized( group.label, LANG ) : '',
			options: group.options
				.filter( ( o ) => ! o.archived || held.includes( o.key ) )
				.map( ( o ) => ( {
					key: o.key,
					label: optionLabel( def, o.key ),
				} ) ),
		} ) )
		.filter( ( group ) => group.options.length );
	const unknown = held.filter( ( key ) => ! known.has( key ) );
	if ( unknown.length ) {
		groups.push( {
			key: '__unknown',
			label: __( 'No longer defined', 'open-bulk-buying' ),
			options: unknown.map( ( key ) => ( { key, label: key } ) ),
		} );
	}
	return groups;
}

const validityMessage = ( validity ) =>
	validity?.required?.message || validity?.custom?.message || '';

function OptionsEdit( {
	def,
	data,
	field,
	onChange,
	hideLabelFromVision,
	validity,
} ) {
	const instanceId = useInstanceId( OptionsEdit, 'openbb-profile-options' );
	const value = field.getValue( { item: data } );
	const groups = offeredGroups( def, value );
	const set = ( next ) =>
		onChange( field.setValue( { item: data, value: next } ) );
	const message = validityMessage( validity );
	const required = !! field.isValid?.required;
	const labelText = required
		? sprintf(
				/* translators: %s: field label. */
				__( '%s (required)', 'open-bulk-buying' ),
				field.label
		  )
		: field.label;

	if ( def.options.style === 'select' && ! def.options.multiple ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ `${ instanceId }-select` }
				label={ labelText }
				hideLabelFromVision={ hideLabelFromVision }
				help={ message || field.description }
				value={ value[ 0 ] || '' }
				onChange={ ( key ) => set( key ? [ key ] : [] ) }
				className={ message ? 'has-error' : undefined }
			>
				<option value="">
					{ __( 'Choose…', 'open-bulk-buying' ) }
				</option>
				{ groups.map( ( group ) => {
					const options = group.options.map( ( option ) => (
						<option key={ option.key } value={ option.key }>
							{ option.label }
						</option>
					) );
					return group.key ? (
						<optgroup key={ group.key } label={ group.label }>
							{ options }
						</optgroup>
					) : (
						options
					);
				} ) }
			</SelectControl>
		);
	}

	const type = def.options.multiple ? 'checkbox' : 'radio';
	const toggle = ( key, checked ) => {
		if ( type === 'radio' ) {
			set( [ key ] );
		} else {
			set(
				checked ? [ ...value, key ] : value.filter( ( k ) => k !== key )
			);
		}
	};
	const chips = ( options ) => (
		<div className="openbb-chips">
			{ options.map( ( option ) => (
				<label key={ option.key } className="openbb-chip">
					<input
						type={ type }
						name={ instanceId }
						value={ option.key }
						checked={ value.includes( option.key ) }
						onChange={ ( event ) =>
							toggle( option.key, event.target.checked )
						}
					/>
					<span>{ option.label }</span>
				</label>
			) ) }
		</div>
	);
	const helpId =
		message || field.description ? `${ instanceId }-help` : undefined;

	return (
		<fieldset
			className="openbb-profile-options"
			aria-describedby={ helpId }
		>
			<legend
				className={
					hideLabelFromVision ? 'screen-reader-text' : undefined
				}
			>
				{ labelText }
			</legend>
			{ groups.map( ( group ) =>
				group.key ? (
					<fieldset
						key={ group.key }
						className="openbb-preview__group"
					>
						<legend>{ group.label }</legend>
						{ chips( group.options ) }
					</fieldset>
				) : (
					<div key="">{ chips( group.options ) }</div>
				)
			) }
			{ type === 'radio' && value.length > 0 && ! required && (
				<Button variant="link" onClick={ () => set( [] ) }>
					{ __( 'Clear choice', 'open-bulk-buying' ) }
				</Button>
			) }
			{ helpId && (
				<p
					id={ helpId }
					className={ `openbb-profile-options__help${
						message ? ' is-error' : ''
					}` }
				>
					{ message || field.description }
				</p>
			) }
		</fieldset>
	);
}

/**
 * DataForm fields for profile definitions. Archived fields are read-only.
 * `required` is only enforced when adding a member (`staff_create`).
 *
 * @param {Array}  defs
 * @param {string} context 'staff' or 'staff_create'.
 * @return {Array} Fields.
 */
export function profileFormFields( defs, context ) {
	return defs.map( ( def ) => {
		const id = PROFILE_PREFIX + def.key;
		const required = context === 'staff_create' && def.required;
		const base = {
			id,
			label: fieldLabel( def ),
			description: localized( def.help, LANG ) || undefined,
			getValue: ( { item } ) => valueOf( item, def ),
			setValue: ( { value } ) => ( { [ id ]: value } ),
			enableSorting: false,
			filterBy: false,
		};

		if ( def.archived ) {
			return {
				...base,
				type: 'text',
				readOnly: true,
				render: ( { item } ) =>
					profileValueText( def, valueOf( item, def ) ),
			};
		}
		if ( def.type === 'boolean' ) {
			return {
				...base,
				type: 'boolean',
				Edit: 'checkbox',
				isValid: required
					? {
							custom: ( item ) =>
								valueOf( item, def )
									? null
									: __(
											'This box must be checked.',
											'open-bulk-buying'
									  ),
					  }
					: {},
			};
		}
		if ( def.type === 'options' ) {
			return {
				...base,
				type: 'array',
				Edit: ( props ) => <OptionsEdit { ...props } def={ def } />,
				isValid: { required, elements: false },
			};
		}
		return {
			...base,
			type: 'text',
			Edit: def.text?.multiline ? 'textarea' : 'text',
			isValid: { required, maxLength: def.text?.max_length },
		};
	} );
}

/**
 * The profile values among a form's edits, keyed by field key.
 *
 * @param {Object} data
 * @param {Object} edits
 */
export function profileEdits( data, edits ) {
	return Object.fromEntries(
		Object.keys( edits )
			.filter( ( id ) => id.startsWith( PROFILE_PREFIX ) )
			.map( ( id ) => [ id.slice( PROFILE_PREFIX.length ), data[ id ] ] )
	);
}

/**
 * Adds field labels to a profile validation error's message.
 *
 * @param {Object} error Normalized API error.
 * @param {Array}  defs
 * @return {Object} Error.
 */
export function withFieldErrors( error, defs ) {
	const errors = error?.data?.errors;
	if ( ! errors || typeof errors !== 'object' ) {
		return error;
	}
	const details = Object.entries( errors ).map( ( [ key, message ] ) => {
		const def = ( defs || [] ).find( ( d ) => d.key === key );
		return `${ def ? fieldLabel( def ) : key }: ${ message }`;
	} );
	return { ...error, message: [ error.message, ...details ].join( ' ' ) };
}
