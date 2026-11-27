/**
 * Pure helpers for the field editor shared by profile fields and poll
 * questions: keys, drafts, and the conversion between the stored option groups
 * and the editor's flat list of rows.
 *
 * Mirrors the rules in OPENBB_Field_Schema; the server checks them again. Each
 * consumer passes its own `config` (see FieldPanel).
 */
import { __, sprintf } from '@wordpress/i18n';
import { localized as localizedText } from '../../utils/config';

export const KEY_MAX_LENGTH = 40;
export const KEY_PATTERN = /^[a-z][a-z0-9_]{0,39}$/;
export const DEFAULT_MAX_LENGTH = 200;
export const MAX_MAX_LENGTH = 5000;
// The fixed option keys of a star rating, lowest first.
export const STAR_KEYS = [ '1', '2', '3', '4', '5' ];

let lastRowId = 0;
const rowId = () => `openbb-option-row-${ ++lastRowId }`;

export const isStars = ( field ) =>
	field?.type === 'options' && field.options?.style === 'stars';

/**
 * Option keys of an options field, in order ([] for other fields).
 *
 * @param {Object} field
 * @return {string[]} Keys.
 */
export const optionKeys = ( field ) =>
	( field?.options?.groups || [] ).flatMap( ( group ) =>
		group.options.map( ( option ) => option.key )
	);

/**
 * Suggested key for a label, like OPENBB_Field_Schema::key_from_label():
 * accents removed, lowercase, other characters as "_". '' when nothing is left.
 *
 * @param {string} label
 * @return {string} Key.
 */
export function keyFromLabel( label ) {
	return ( label || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^[0-9_]+/, '' )
		.slice( 0, KEY_MAX_LENGTH )
		.replace( /_+$/, '' );
}

/**
 * Cleans what someone types into a key box: lowercase, spaces and dashes as "_".
 *
 * @param {string} value
 * @return {string} Key.
 */
export const cleanKeyInput = ( value ) =>
	value
		.toLowerCase()
		.replace( /[\s-]+/g, '_' )
		.replace( /[^a-z0-9_]/g, '' )
		.slice( 0, KEY_MAX_LENGTH );

/**
 * `base`, or `base_2`, `base_3`… when taken. With an empty base,
 * `{fallback}_1`, `{fallback}_2`…
 *
 * @param {string}      base
 * @param {Set<string>} taken
 * @param {string}      fallback e.g. 'field'.
 * @return {string} Unused key.
 */
export function uniqueKey( base, taken, fallback ) {
	if ( ! base ) {
		let n = 1;
		while ( taken.has( `${ fallback }_${ n }` ) ) {
			n++;
		}
		return `${ fallback }_${ n }`;
	}
	if ( ! taken.has( base ) ) {
		return base;
	}
	let n = 1;
	let candidate;
	do {
		const suffix = `_${ ++n }`;
		candidate = base.slice( 0, KEY_MAX_LENGTH - suffix.length ) + suffix;
	} while ( taken.has( candidate ) );
	return candidate;
}

/**
 * A localized map's text for `lang`, falling back to the default.
 *
 * @param {Object} value { default, [lang] }.
 * @param {string} lang  '' for the default.
 * @return {string} Text.
 */
export const localized = ( value, lang = '' ) => localizedText( value, lang );

const isBlank = ( value ) => ! ( value?.default || '' ).trim();

/**
 * Stored option groups → editor rows. Rows are option rows and group heading
 * rows; an option belongs to the closest heading above it.
 *
 * @param {Array}    groups
 * @param {Function} isSaved Option key => whether answers may hold it, so it
 *                           can be archived but not removed or renamed.
 * @return {Array} Rows.
 */
export function groupsToRows( groups = [], isSaved = () => true ) {
	const rows = [];
	for ( const group of groups ) {
		if ( group.key ) {
			rows.push( {
				id: rowId(),
				kind: 'group',
				key: group.key,
				label: group.label || { default: '' },
			} );
		}
		for ( const option of group.options ) {
			rows.push( {
				id: rowId(),
				kind: 'option',
				key: option.key,
				label: option.label,
				archived: !! option.archived,
				saved: !! isSaved( option.key ),
				keyTouched: true,
			} );
		}
	}
	return rows;
}

/**
 * Editor rows → stored option groups. Options above the first heading are
 * ungrouped (group key ""). New headings get a key from their label; group keys
 * never appear in member data, so they only need to be unique.
 *
 * @param {Array} rows
 * @return {Array} Groups.
 */
export function rowsToGroups( rows ) {
	const taken = new Set(
		rows
			.filter( ( row ) => row.kind === 'group' && row.key )
			.map( ( row ) => row.key )
	);
	const groups = [ { key: '', label: null, options: [] } ];

	for ( const row of rows ) {
		if ( row.kind === 'group' ) {
			const key =
				row.key ||
				uniqueKey( keyFromLabel( row.label.default ), taken, 'group' );
			taken.add( key );
			groups.push( { key, label: row.label, options: [] } );
		} else {
			groups[ groups.length - 1 ].options.push( {
				key: row.key,
				label: row.label,
				archived: !! row.archived,
			} );
		}
	}

	return groups[ 0 ].options.length ? groups : groups.slice( 1 );
}

export const newOptionRow = () => ( {
	id: rowId(),
	kind: 'option',
	key: '',
	label: { default: '' },
	archived: false,
	saved: false,
	keyTouched: false,
} );

export const newGroupRow = () => ( {
	id: rowId(),
	kind: 'group',
	key: '',
	label: { default: '' },
} );

const defaultText = () => ( {
	multiline: false,
	max_length: DEFAULT_MAX_LENGTH,
} );
const defaultOptions = () => ( { style: 'select', multiple: false } );

/**
 * The editor's working copy of a field. Options are kept as `rows`.
 *
 * @param {Object|null} field         The field, or null for a new one.
 * @param {Object}      config        Consumer config.
 * @param {Object}      config.extras The consumer's own settings, with their
 *                                    value for a new field.
 * @param {Object|null} saved         The version of the field answers may
 *                                    depend on; its options can't be removed.
 * @return {Object} Draft.
 */
export function toDraft( field, { extras = {} } = {}, saved = field ) {
	if ( ! field ) {
		return {
			key: '',
			keyTouched: false,
			label: { default: '' },
			help: { default: '' },
			type: 'text',
			archived: false,
			...extras,
			text: defaultText(),
			options: defaultOptions(),
			rows: [ newOptionRow() ],
		};
	}

	const { options, ...rest } = field;
	const savedKeys = new Set( optionKeys( saved ) );
	const own = Object.fromEntries(
		Object.entries( extras ).map( ( [ name, value ] ) => [
			name,
			field[ name ] ?? value,
		] )
	);
	return {
		...rest,
		...own,
		keyTouched: true,
		text: field.text || defaultText(),
		options: options
			? { style: options.style, multiple: options.multiple }
			: defaultOptions(),
		// The options of a star rating are fixed, so it has no rows to edit.
		rows:
			options && ! isStars( field )
				? groupsToRows( options.groups, ( key ) =>
						savedKeys.has( key )
				  )
				: [ newOptionRow() ],
	};
}

/**
 * A draft → the field definition the API takes.
 *
 * @param {Object} draft
 * @param {Object} config        Consumer config.
 * @param {Object} config.extras The consumer's own settings.
 * @return {Object} Field.
 */
export function fromDraft( draft, { extras = {} } = {} ) {
	const field = {
		key: draft.key,
		label: draft.label,
		help: draft.help,
		type: draft.type,
		archived: draft.archived,
	};
	for ( const name of Object.keys( extras ) ) {
		field[ name ] = draft[ name ];
	}
	if ( draft.type === 'text' ) {
		field.text = draft.text;
	}
	if ( draft.type === 'options' ) {
		// The server generates the options of a star rating.
		field.options =
			draft.options.style === 'stars'
				? { style: 'stars', multiple: false }
				: { ...draft.options, groups: rowsToGroups( draft.rows ) };
	}
	return field;
}

/**
 * Why a new field's key can't be used, or null.
 *
 * @param {string}      key
 * @param {Set<string>} otherKeys Keys of the other fields.
 * @param {Object}      schema    For retired_keys and reserved_keys.
 * @param {Object}      config    Consumer config, for `strings.keyInUse`.
 * @return {string|null} Message.
 */
export function fieldKeyProblem( key, otherKeys, schema, config = {} ) {
	if ( ! KEY_PATTERN.test( key ) ) {
		return __(
			'Use lowercase letters, numbers and underscores, starting with a letter.',
			'open-bulk-buying'
		);
	}
	if ( ( schema.reserved_keys || [] ).includes( key ) ) {
		return __(
			'This key is reserved. Choose another key.',
			'open-bulk-buying'
		);
	}
	if ( ( schema.retired_keys || [] ).includes( key ) ) {
		return __(
			'This key belonged to a deleted field and can’t be reused.',
			'open-bulk-buying'
		);
	}
	if ( otherKeys.has( key ) ) {
		return (
			config.strings?.keyInUse ||
			__( 'Another field already uses this key.', 'open-bulk-buying' )
		);
	}
	return null;
}

/**
 * Everything wrong with a draft, as messages (empty when it can be saved).
 *
 * @param {Object}      draft
 * @param {Set<string>} otherKeys Keys of the other fields.
 * @param {Object}      schema
 * @param {Object}      config    Consumer config.
 * @return {string[]} Messages.
 */
export function draftProblems( draft, otherKeys, schema, config = {} ) {
	const problems = [];

	if ( isBlank( draft.label ) ) {
		problems.push( __( 'Add a label.', 'open-bulk-buying' ) );
	}
	const keyProblem = fieldKeyProblem( draft.key, otherKeys, schema, config );
	if ( keyProblem ) {
		problems.push(
			/* translators: %s: why the key can't be used. */
			sprintf( __( 'Key: %s', 'open-bulk-buying' ), keyProblem )
		);
	}

	if ( draft.type === 'text' ) {
		const max = Number( draft.text.max_length );
		if ( ! Number.isInteger( max ) || max < 1 || max > MAX_MAX_LENGTH ) {
			problems.push(
				sprintf(
					/* translators: %d: largest allowed maximum length. */
					__(
						'The maximum length must be between 1 and %d.',
						'open-bulk-buying'
					),
					MAX_MAX_LENGTH
				)
			);
		}
	}

	if ( draft.type === 'options' && draft.options.style !== 'stars' ) {
		const options = draft.rows.filter( ( row ) => row.kind === 'option' );
		if ( ! options.length ) {
			problems.push(
				__( 'Add at least one option.', 'open-bulk-buying' )
			);
		}
		if ( options.some( ( row ) => isBlank( row.label ) ) ) {
			problems.push(
				__( 'Every option needs a label.', 'open-bulk-buying' )
			);
		}
		const seen = new Set();
		for ( const row of options ) {
			if ( ! KEY_PATTERN.test( row.key ) || seen.has( row.key ) ) {
				problems.push(
					sprintf(
						/* translators: %s: option key. */
						__(
							'The option key “%s” is invalid or used twice.',
							'open-bulk-buying'
						),
						row.key
					)
				);
				break;
			}
			seen.add( row.key );
		}
		if (
			draft.rows.some(
				( row ) => row.kind === 'group' && isBlank( row.label )
			)
		) {
			problems.push(
				__( 'Every group needs a label.', 'open-bulk-buying' )
			);
		}
	}

	return problems;
}
