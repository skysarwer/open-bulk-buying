import {
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { config, languageLabel } from '../../../utils/config';
import { localized, rowsToGroups } from './utils';

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
 * How the field will look on member forms, with native controls, so grouping
 * mistakes show up before saving. The real forms use the same markup.
 *
 * @param {Object} props
 * @param {Object} props.draft
 */
export default function FieldPreview( { draft } ) {
	const instanceId = useInstanceId( FieldPreview, 'openbb-preview' );
	const [ lang, setLang ] = useState( '' );
	const id = `${ instanceId }-input`;
	const helpId = localized( draft.help, lang )
		? `${ instanceId }-help`
		: undefined;

	let control;
	if ( draft.type === 'boolean' ) {
		control = (
			<div className="openbb-preview__checkbox">
				<input type="checkbox" id={ id } aria-describedby={ helpId } />
				<Label field={ draft } lang={ lang } htmlFor={ id } />
			</div>
		);
	} else if ( draft.type === 'options' && draft.options.style === 'chips' ) {
		control = (
			<fieldset
				className="openbb-preview__fieldset"
				aria-describedby={ helpId }
			>
				<Label field={ draft } lang={ lang } as="legend" />
				<ChipsPreview draft={ draft } lang={ lang } name={ id } />
			</fieldset>
		);
	} else if ( draft.type === 'options' ) {
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
				{ draft.visibility === 'staff' && (
					<p className="openbb-preview__note">
						{ __(
							'Staff only: this field never appears on member forms.',
							'open-bulk-buying'
						) }
					</p>
				) }
			</div>
			<p className="openbb-muted">
				{ sprintf(
					/* translators: %s: where the field appears. */
					__( 'Shown on: %s', 'open-bulk-buying' ),
					draft.visibility === 'staff'
						? __( 'the Members screen', 'open-bulk-buying' )
						: __(
								'signup, member account and the Members screen',
								'open-bulk-buying'
						  )
				) }
			</p>
		</VStack>
	);
}
