import {
	Notice,
	RadioControl,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { LocalizedTextControl } from '../../../components/field-editor';
import { localized } from '../../../utils/config';
import { RuleControl } from '../polls/settings-form';
import { CONTENT_MAX, CTA_TEXT_MAX, CTA_URL_MAX, hasCta } from './utils';

function Heading( { children } ) {
	return <h4 className="openbb-section-title">{ children }</h4>;
}

/**
 * How the notice looks to members: an info notice is blue, an alert yellow.
 * Admin notices use the same two colors, so the preview is drawn with one.
 *
 * @param {Object} props
 * @param {Object} props.draft
 */
function Preview( { draft } ) {
	const text = localized( draft.content );
	if ( ! text ) {
		return null;
	}

	return (
		<div className="openbb-notice-preview">
			<span className="openbb-muted">
				{ __( 'Preview', 'open-bulk-buying' ) }
			</span>
			<Notice
				status={ draft.type === 'alert' ? 'warning' : 'info' }
				isDismissible={ false }
				// A button that does nothing: the preview must not follow the link being typed.
				actions={
					hasCta( draft )
						? [
								{
									label: localized( draft.cta_text ),
									onClick: () => {},
								},
						  ]
						: []
				}
			>
				{ text }
			</Notice>
		</div>
	);
}

/**
 * A notice's settings: its kind, text, optional button and when it is shown.
 *
 * @param {Object}   props
 * @param {Object}   props.draft    From toSettings().
 * @param {Function} props.onChange Receives the changed settings.
 */
export default function SettingsForm( { draft, onChange } ) {
	return (
		<VStack spacing={ 4 } className="openbb-notice-settings">
			<RadioControl
				label={ __( 'Kind', 'open-bulk-buying' ) }
				selected={ draft.type }
				onChange={ ( type ) => onChange( { type } ) }
				options={ [
					{
						value: 'info',
						label: __(
							'Info (blue): news and reminders',
							'open-bulk-buying'
						),
					},
					{
						value: 'alert',
						label: __(
							'Alert (yellow): something members should not miss',
							'open-bulk-buying'
						),
					},
				] }
			/>
			<LocalizedTextControl
				label={ __( 'Text', 'open-bulk-buying' ) }
				value={ draft.content }
				multiline
				maxLength={ CONTENT_MAX }
				onChange={ ( content ) => onChange( { content } ) }
				help={ __(
					'Plain text. Line breaks are kept.',
					'open-bulk-buying'
				) }
			/>

			<Heading>{ __( 'Button (optional)', 'open-bulk-buying' ) }</Heading>
			<LocalizedTextControl
				label={ __( 'Button text', 'open-bulk-buying' ) }
				value={ draft.cta_text }
				maxLength={ CTA_TEXT_MAX }
				onChange={ ( cta_text ) => onChange( { cta_text } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="url"
				label={ __( 'Button link', 'open-bulk-buying' ) }
				help={ __(
					'A web address, an email link (mailto:) or a phone link (tel:). Add both the text and the link, or neither.',
					'open-bulk-buying'
				) }
				value={ draft.cta_url }
				maxLength={ CTA_URL_MAX }
				onChange={ ( cta_url ) => onChange( { cta_url } ) }
			/>

			<Heading>{ __( 'When it is shown', 'open-bulk-buying' ) }</Heading>
			<RuleControl
				side="open"
				label={ __( 'Shown', 'open-bulk-buying' ) }
				draft={ draft }
				onChange={ onChange }
				rules={ [
					{
						value: 'publish',
						label: __(
							'As soon as it is published',
							'open-bulk-buying'
						),
					},
					{
						value: 'stage',
						label: __(
							'When the cycle reaches a stage',
							'open-bulk-buying'
						),
					},
					{
						value: 'date',
						label: __( 'On a date', 'open-bulk-buying' ),
					},
				] }
			/>
			<RuleControl
				side="close"
				label={ __( 'Hidden', 'open-bulk-buying' ) }
				draft={ draft }
				onChange={ onChange }
				rules={ [
					{
						value: 'manual',
						label: __( 'When I unpublish it', 'open-bulk-buying' ),
					},
					{
						value: 'stage',
						label: __(
							'When the cycle reaches a stage',
							'open-bulk-buying'
						),
					},
					{
						value: 'date',
						label: __( 'On a date', 'open-bulk-buying' ),
					},
				] }
			/>
			<p className="openbb-muted">
				{ __(
					'Only a published notice is shown, on the order page and wherever members are told ordering is closed. Stage rules follow the cycle’s current stage, so moving the cycle back shows or hides the notice again. Dates use the site’s timezone.',
					'open-bulk-buying'
				) }
			</p>

			<Preview draft={ draft } />
		</VStack>
	);
}
