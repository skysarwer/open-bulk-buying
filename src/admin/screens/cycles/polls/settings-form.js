import {
	CheckboxControl,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { LocalizedTextControl } from '../../../components/field-editor';
import { INTRO_MAX, TITLE_MAX, stageLabel, stages } from './utils';

function Heading( { children } ) {
	return <h4 className="openbb-section-title">{ children }</h4>;
}

/**
 * When something opens or closes: a rule, plus the stage or date it needs.
 * Reads and writes `{side}_rule`, `{side}_stage` and `{side}_at` of the draft.
 * Shared with the cycle notices.
 *
 * @param {Object}   props
 * @param {string}   props.side     'open' or 'close'.
 * @param {string}   props.label
 * @param {Array}    props.rules    Rule options for this side.
 * @param {Object}   props.draft
 * @param {Function} props.onChange Receives the changed settings.
 */
export function RuleControl( { side, label, rules, draft, onChange } ) {
	const rule = draft[ `${ side }_rule` ];

	return (
		<VStack spacing={ 2 }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ rule }
				onChange={ ( value ) =>
					onChange( { [ `${ side }_rule` ]: value } )
				}
				options={ rules }
			/>
			{ rule === 'stage' && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Cycle stage', 'open-bulk-buying' ) }
					hideLabelFromVision
					value={ draft[ `${ side }_stage` ] }
					onChange={ ( value ) =>
						onChange( { [ `${ side }_stage` ]: value } )
					}
					options={ stages()
						// Every cycle has reached its first stage, so nothing can close there.
						.slice( side === 'close' ? 1 : 0 )
						.map( ( stage ) => ( {
							value: stage,
							label: stageLabel( stage ),
						} ) ) }
				/>
			) }
			{ rule === 'date' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="datetime-local"
					label={ __( 'Date and time', 'open-bulk-buying' ) }
					hideLabelFromVision
					value={ draft[ `${ side }_at` ] }
					onChange={ ( value ) =>
						onChange( { [ `${ side }_at` ]: value } )
					}
				/>
			) }
		</VStack>
	);
}

/**
 * The cycles whose members can answer: any committed order in a ticked cycle
 * qualifies. The count is how many members that is, per cycle.
 *
 * @param {Object}   props
 * @param {Object}   props.draft
 * @param {Object}   props.audience  { suggested, cycles } from the API, or null while loading.
 * @param {number[]} props.missing   Selected cycles that no longer exist.
 * @param {Function} props.onChange
 */
function AudienceCycles( { draft, audience, missing, onChange } ) {
	if ( ! audience ) {
		return (
			<p className="openbb-muted">
				{ __( 'Loading cycles…', 'open-bulk-buying' ) }
			</p>
		);
	}
	const selected = draft.audience_cycles;
	const known = new Set( audience.cycles.map( ( cycle ) => cycle.id ) );
	const toggle = ( id, isChecked ) =>
		onChange( {
			audience_cycles: isChecked
				? [ ...selected, id ]
				: selected.filter( ( other ) => other !== id ),
		} );

	return (
		<VStack spacing={ 2 }>
			{ missing.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of cycles. */
						_n(
							'%d chosen cycle was deleted since and no longer counts.',
							'%d chosen cycles were deleted since and no longer count.',
							missing.length,
							'open-bulk-buying'
						),
						missing.length
					) }
				</Notice>
			) }
			{ ! selected.some( ( id ) => known.has( id ) ) && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No cycle is chosen, so nobody can answer this poll.',
						'open-bulk-buying'
					) }
				</Notice>
			) }
			<fieldset className="openbb-poll-cycles">
				<legend className="screen-reader-text">
					{ __( 'Cycles', 'open-bulk-buying' ) }
				</legend>
				{ audience.cycles.map( ( cycle ) => (
					<CheckboxControl
						key={ cycle.id }
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: 1: cycle name, 2: cycle stage, 3: "12 members ordered". */
							__( '%1$s (%2$s) · %3$s', 'open-bulk-buying' ),
							cycle.name,
							cycle.status_label,
							sprintf(
								/* translators: %d: number of members. */
								_n(
									'%d member ordered',
									'%d members ordered',
									cycle.members,
									'open-bulk-buying'
								),
								cycle.members
							)
						) }
						help={
							cycle.id === audience.suggested
								? __(
										'Suggested: the most recent earlier cycle with orders.',
										'open-bulk-buying'
								  )
								: undefined
						}
						checked={ selected.includes( cycle.id ) }
						onChange={ ( isChecked ) =>
							toggle( cycle.id, isChecked )
						}
					/>
				) ) }
			</fieldset>
			<p className="openbb-muted">
				{ __(
					'A member qualifies with a submitted, prepaid or fulfilled order in any ticked cycle. Draft and cancelled orders don’t count. The audience is exactly these cycles and never changes by itself.',
					'open-bulk-buying'
				) }
			</p>
		</VStack>
	);
}

/**
 * A poll's settings: what it's called, when it's open, where members see it
 * and who can answer.
 *
 * @param {Object}   props
 * @param {Object}   props.draft    From toSettings().
 * @param {Object}   props.audience { suggested, cycles }, or null while loading.
 * @param {number[]} props.missing  Selected cycles that no longer exist.
 * @param {Function} props.onChange Receives the changed settings.
 */
export default function SettingsForm( { draft, audience, missing, onChange } ) {
	const togglePlacement = ( placement, isChecked ) =>
		onChange( {
			placements: isChecked
				? [ ...draft.placements, placement ]
				: draft.placements.filter( ( other ) => other !== placement ),
		} );

	const setAudience = ( value ) => {
		const changes = { audience: value };
		// Start from the suggestion; staff review it.
		if (
			value === 'ordered_in_cycles' &&
			! draft.audience_cycles.length &&
			audience?.suggested
		) {
			changes.audience_cycles = [ audience.suggested ];
		}
		onChange( changes );
	};

	return (
		<VStack spacing={ 4 } className="openbb-poll-settings">
			<LocalizedTextControl
				label={ __( 'Title', 'open-bulk-buying' ) }
				value={ draft.title }
				maxLength={ TITLE_MAX }
				onChange={ ( title ) => onChange( { title } ) }
			/>
			<LocalizedTextControl
				label={ __( 'Introduction', 'open-bulk-buying' ) }
				value={ draft.intro }
				multiline
				maxLength={ INTRO_MAX }
				onChange={ ( intro ) => onChange( { intro } ) }
				help={ __(
					'Shown to members above the questions. Plain text.',
					'open-bulk-buying'
				) }
			/>

			<Heading>{ __( 'When it is open', 'open-bulk-buying' ) }</Heading>
			<RuleControl
				side="open"
				label={ __( 'Opens', 'open-bulk-buying' ) }
				draft={ draft }
				onChange={ onChange }
				rules={ [
					{
						value: 'stage',
						label: __(
							'When the cycle reaches a stage',
							'open-bulk-buying'
						),
					},
					{
						value: 'publish',
						label: __(
							'As soon as it is published',
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
				label={ __( 'Closes', 'open-bulk-buying' ) }
				draft={ draft }
				onChange={ onChange }
				rules={ [
					{
						value: 'stage',
						label: __(
							'When the cycle reaches a stage',
							'open-bulk-buying'
						),
					},
					{
						value: 'manual',
						label: __( 'When staff close it', 'open-bulk-buying' ),
					},
					{
						value: 'date',
						label: __( 'On a date', 'open-bulk-buying' ),
					},
				] }
			/>
			<p className="openbb-muted">
				{ __(
					'This is independent of the ordering dates. Stage rules follow the cycle’s current stage, so moving the cycle back opens or closes the poll again. Closing a poll by hand is final.',
					'open-bulk-buying'
				) }
			</p>

			<Heading>
				{ __( 'Where members see it', 'open-bulk-buying' ) }
			</Heading>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Card on the order page', 'open-bulk-buying' ) }
				help={ __(
					'An invitation above the product list that opens the poll. Also shown when ordering is closed.',
					'open-bulk-buying'
				) }
				checked={ draft.placements.includes( 'card' ) }
				onChange={ ( isChecked ) =>
					togglePlacement( 'card', isChecked )
				}
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'Questions on the order thank-you page',
					'open-bulk-buying'
				) }
				help={ __(
					'Members can answer after placing their order. Saving answers does not change the order.',
					'open-bulk-buying'
				) }
				checked={ draft.placements.includes( 'thank_you' ) }
				onChange={ ( isChecked ) =>
					togglePlacement( 'thank_you', isChecked )
				}
			/>
			<p className="openbb-muted">
				{ __(
					'Members can always answer on a page with the [openbb_polls] shortcode or through the poll’s direct link, whether or not they order.',
					'open-bulk-buying'
				) }
			</p>

			<Heading>{ __( 'Who can answer', 'open-bulk-buying' ) }</Heading>
			<RadioControl
				label={ __( 'Audience', 'open-bulk-buying' ) }
				hideLabelFromVision
				selected={ draft.audience }
				onChange={ setAudience }
				options={ [
					{
						value: 'all',
						label: __( 'All active members', 'open-bulk-buying' ),
					},
					{
						value: 'ordered_in_cycles',
						label: __(
							'Members who ordered in chosen cycles',
							'open-bulk-buying'
						),
					},
				] }
			/>
			{ draft.audience === 'ordered_in_cycles' && (
				<AudienceCycles
					draft={ draft }
					audience={ audience }
					missing={ missing }
					onChange={ onChange }
				/>
			) }
		</VStack>
	);
}
