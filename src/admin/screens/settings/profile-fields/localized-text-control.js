import {
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { config, languageLabel } from '../../../utils/config';

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
				label={ label }
				hideLabelFromVision={ hideLabelFromVision }
				placeholder={ placeholder }
				value={ value?.default || '' }
				onChange={ ( text ) => set( 'default', text ) }
				help={ help }
			/>
			{ config.translationLanguages.map( ( lang ) => (
				<TextControl
					key={ lang }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					/* translators: 1: field name, 2: language code. */
					label={ sprintf(
						__( '%1$s (%2$s)', 'open-bulk-buying' ),
						label,
						languageLabel( lang )
					) }
					hideLabelFromVision={ hideLabelFromVision }
					placeholder={
						hideLabelFromVision
							? sprintf(
									/* translators: %s: language code. */
									__(
										'Translation (%s)',
										'open-bulk-buying'
									),
									languageLabel( lang )
							  )
							: undefined
					}
					value={ value?.[ lang ] || '' }
					onChange={ ( text ) => set( lang, text ) }
				/>
			) ) }
		</VStack>
	);
}
