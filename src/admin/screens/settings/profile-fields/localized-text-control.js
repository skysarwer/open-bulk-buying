import {
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { config, localizedFieldLabel } from '../../../utils/config';

/**
 * Inputs for a localized text map: the default text plus one input per
 * translation language. Empty translations fall back to the default.
 *
 * @param {Object}   props
 * @param {string}   props.id                  Id of the default input.
 * @param {string}   props.label
 * @param {Object}   props.value               { default, [lang] }.
 * @param {Function} props.onChange            Receives the whole map.
 * @param {string}   props.help
 * @param {boolean}  props.hideLabelFromVision
 * @param {string}   props.placeholder
 */
export default function LocalizedTextControl( {
	id,
	label,
	value,
	onChange,
	help,
	hideLabelFromVision = false,
	placeholder,
} ) {
	const set = ( lang, text ) => onChange( { ...value, [ lang ]: text } );

	return (
		<VStack spacing={ 2 }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ id }
				label={ localizedFieldLabel( label ) }
				hideLabelFromVision={ hideLabelFromVision }
				placeholder={
					placeholder ? localizedFieldLabel( placeholder ) : undefined
				}
				value={ value?.default || '' }
				onChange={ ( text ) => set( 'default', text ) }
				help={ help }
			/>
			{ config.translationLanguages.map( ( lang ) => (
				<TextControl
					key={ lang }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ localizedFieldLabel( label, lang ) }
					hideLabelFromVision={ hideLabelFromVision }
					placeholder={
						hideLabelFromVision && placeholder
							? localizedFieldLabel( placeholder, lang )
							: undefined
					}
					value={ value?.[ lang ] || '' }
					onChange={ ( text ) => set( lang, text ) }
				/>
			) ) }
		</VStack>
	);
}
