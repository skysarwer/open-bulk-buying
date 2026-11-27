import {
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { config, languageLabel } from '../../utils/config';
import { STAR_KEYS, localized, rowsToGroups } from './utils';

function Label( { field, lang, htmlFor, as: Tag = 'label' } ) {
	return (
		<Tag className="openbb-preview__label" htmlFor={ htmlFor }>
			{ localized( field.label, lang ) ||
				__( '(No label)', 'open-bulk-buying' ) }
			{ field.required && (
				<span className="openbb-preview__required">
					{ ' ' }
					{ __( '(required)', 'open-bulk-buying' ) }
				</span>
			) }
		</Tag>
	);
}

function Help( { field, lang, id } ) {
	const help = localized( field.help, lang );
	return help ? (
		<p className="openbb-preview__help" id={ id }>
			{ help }
		</p>
	) : null;
}

/** Groups with only their active options, without empty groups. */
const activeGroups = ( draft ) =>
	rowsToGroups( draft.rows )
		.map( ( group ) => ( {
			...group,
			options: group.options.filter( ( option ) => ! option.archived ),
		} ) )
		.filter( ( group ) => group.options.length );

function SelectPreview( { draft, lang, id, helpId } ) {
	const groups = activeGroups( draft );
	const optionLabel = ( option ) =>
		localized( option.label, lang ) || option.key;

	return (
		<select id={ id } aria-describedby={ helpId } defaultValue="">
			<option value="">{ __( 'Choose…', 'open-bulk-buying' ) }</option>
			{ groups.map( ( group ) => {
				const options = group.options.map( ( option ) => (
					<option key={ option.key } value={ option.key }>
						{ optionLabel( option ) }
					</option>
				) );
				return group.key ? (
					<optgroup
						key={ group.key }
						label={ localized( group.label, lang ) }
					>
						{ options }
					</optgroup>
				) : (
					options
				);
			} ) }
		</select>
	);
}

function Chips( { options, name, type, lang } ) {
	return (
		<div className="openbb-chips">
			{ options.map( ( option ) => (
				<label key={ option.key } className="openbb-chip">
					<input type={ type } name={ name } value={ option.key } />
					<span>
						{ localized( option.label, lang ) || option.key }
					</span>
				</label>
			) ) }
		</div>
	);
}

function ChipsPreview( { draft, lang, name } ) {
	const type = draft.options.multiple ? 'checkbox' : 'radio';
	return activeGroups( draft ).map( ( group ) =>
		group.key ? (
			<fieldset key={ group.key } className="openbb-preview__group">
				<legend>{ localized( group.label, lang ) }</legend>
				<Chips
					options={ group.options }
					name={ name }
					type={ type }
					lang={ lang }
				/>
			</fieldset>
		) : (
			<Chips
				key=""
				options={ group.options }
				name={ name }
				type={ type }
				lang={ lang }
			/>
		)
	);
}

/**
 * Five radios in reading order, each drawn as the site's star icon. A star is
 * filled when the selected rating is at least its own, as on the member form.
 *
 * @param {Object} props
 * @param {string} props.name Radio group name.
 */
function StarsPreview( { name } ) {
	const [ rating, setRating ] = useState( 0 );

	return (
		<div className="openbb-stars">
			{ STAR_KEYS.map( ( key ) => {
				const value = Number( key );
				return (
					<label
						key={ key }
						className={ `openbb-star${
							rating >= value ? ' is-filled' : ''
						}` }
					>
						<input
							type="radio"
							name={ name }
							value={ key }
							checked={ rating === value }
							onChange={ () => setRating( value ) }
						/>
						<span
							className="openbb-star__icon"
							aria-hidden="true"
							// Sanitized on the server (openbb_star_icon()).
							dangerouslySetInnerHTML={ {
								__html: config.starIcon,
							} }
						/>
						<span className="screen-reader-text">
							{ sprintf(
								/* translators: 1: rating, 2: highest rating, e.g. "3 of 5 stars". */
								_n(
									'%1$d of %2$d star',
									'%1$d of %2$d stars',
									value,
									'open-bulk-buying'
								),
								value,
								STAR_KEYS.length
							) }
						</span>
					</label>
				);
			} ) }
		</div>
	);
}

const YES_NO = [
	{ key: '1', label: { default: __( 'Yes', 'open-bulk-buying' ) } },
	{ key: '0', label: { default: __( 'No', 'open-bulk-buying' ) } },
];

/**
 * How the field will look on member forms, with native controls, so grouping
 * mistakes show up before saving. The real forms use the same markup.
 *
 * `options` comes from the consumer's config:
 * - yesNo     A true/false field is two choices that start unselected, not a checkbox.
 * - clearable Single choices can be taken back (nothing is required).
 * - note      draft => text under the control, or ''.
 * - shownOn   draft => where the field appears.
 *
 * @param {Object} props
 * @param {Object} props.draft
 * @param {Object} props.options
 */
export default function FieldPreview( { draft, options = {} } ) {
	const instanceId = useInstanceId( FieldPreview, 'openbb-preview' );
	const [ lang, setLang ] = useState( '' );
	const id = `${ instanceId }-input`;
	const helpId = localized( draft.help, lang )
		? `${ instanceId }-help`
		: undefined;
	const isOptions = draft.type === 'options';
	const isSingleChoice =
		( draft.type === 'boolean' && options.yesNo ) ||
		( isOptions &&
			draft.options.style !== 'select' &&
			! draft.options.multiple );

	let control;
	if ( draft.type === 'boolean' && options.yesNo ) {
		control = (
			<fieldset
				className="openbb-preview__fieldset"
				aria-describedby={ helpId }
			>
				<Label field={ draft } lang={ lang } as="legend" />
				<Chips options={ YES_NO } name={ id } type="radio" lang="" />
			</fieldset>
		);
	} else if ( draft.type === 'boolean' ) {
		control = (
			<div className="openbb-preview__checkbox">
				<input type="checkbox" id={ id } aria-describedby={ helpId } />
				<Label field={ draft } lang={ lang } htmlFor={ id } />
			</div>
		);
	} else if ( isOptions && draft.options.style === 'stars' ) {
		control = (
			<fieldset
				className="openbb-preview__fieldset"
				aria-describedby={ helpId }
			>
				<Label field={ draft } lang={ lang } as="legend" />
				<StarsPreview name={ id } />
			</fieldset>
		);
	} else if ( isOptions && draft.options.style === 'chips' ) {
		control = (
			<fieldset
				className="openbb-preview__fieldset"
				aria-describedby={ helpId }
			>
				<Label field={ draft } lang={ lang } as="legend" />
				<ChipsPreview draft={ draft } lang={ lang } name={ id } />
			</fieldset>
		);
	} else if ( isOptions ) {
		control = (
			<>
				<Label field={ draft } lang={ lang } htmlFor={ id } />
				<SelectPreview
					draft={ draft }
					lang={ lang }
					id={ id }
					helpId={ helpId }
				/>
			</>
		);
	} else {
		const Input = draft.text.multiline ? 'textarea' : 'input';
		control = (
			<>
				<Label field={ draft } lang={ lang } htmlFor={ id } />
				<Input
					id={ id }
					aria-describedby={ helpId }
					maxLength={ Number( draft.text.max_length ) || undefined }
					rows={ draft.text.multiline ? 3 : undefined }
					className="regular-text"
				/>
			</>
		);
	}

	const note = options.note?.( draft );
	const shownOn = options.shownOn?.( draft );

	return (
		<VStack spacing={ 3 } className="openbb-preview">
			{ config.translationLanguages.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Preview language', 'open-bulk-buying' ) }
					value={ lang }
					onChange={ setLang }
					options={ [
						{
							value: '',
							label: __( 'Default', 'open-bulk-buying' ),
						},
						...config.translationLanguages.map( ( code ) => ( {
							value: code,
							label: languageLabel( code ),
						} ) ),
					] }
				/>
			) }
			<div className="openbb-preview__frame">
				{ control }
				<Help field={ draft } lang={ lang } id={ helpId } />
				{ options.clearable && isSingleChoice && (
					<p className="openbb-preview__note">
						{ __(
							'Members get a “Clear answer” button once they have chosen.',
							'open-bulk-buying'
						) }
					</p>
				) }
				{ note && <p className="openbb-preview__note">{ note }</p> }
			</div>
			{ shownOn && (
				<p className="openbb-muted">
					{ sprintf(
						/* translators: %s: where the field appears. */
						__( 'Shown on: %s', 'open-bulk-buying' ),
						shownOn
					) }
				</p>
			) }
		</VStack>
	);
}
