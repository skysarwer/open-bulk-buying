import {
	Button,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus, trash } from '@wordpress/icons';

import { SortableList, moveItem } from '../../../components';
import LocalizedTextControl from './localized-text-control';
import {
	cleanKeyInput,
	keyFromLabel,
	newGroupRow,
	newOptionRow,
	uniqueKey,
} from './utils';

/**
 * Position labels for the rows: "Option 3", "Group 1".
 *
 * @param {Array} rows
 * @return {Object} id => name.
 */
function rowNames( rows ) {
	const names = {};
	let options = 0;
	let groups = 0;
	for ( const row of rows ) {
		names[ row.id ] =
			row.kind === 'group'
				? /* translators: %d: group number. */
				  sprintf( __( 'Group %d', 'open-bulk-buying' ), ++groups )
				: /* translators: %d: option number. */
				  sprintf( __( 'Option %d', 'open-bulk-buying' ), ++options );
	}
	return names;
}

function OptionRow( { row, name, onChange, onRemove } ) {
	return (
		<div className="openbb-option-row">
			<div className="openbb-option-row__label">
				<LocalizedTextControl
					id={ `${ row.id }-label` }
					label={ name }
					hideLabelFromVision
					placeholder={ __( 'Option label', 'open-bulk-buying' ) }
					value={ row.label }
					onChange={ ( label ) => onChange( { label } ) }
				/>
			</div>
			<div className="openbb-option-row__key">
				{ row.saved ? (
					<code
						title={ __(
							'Saved option keys can’t change.',
							'open-bulk-buying'
						) }
					>
						{ row.key }
					</code>
				) : (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/* translators: %s: option position, e.g. "Option 3". */
						label={ sprintf(
							__( 'Key of %s', 'open-bulk-buying' ),
							name
						) }
						hideLabelFromVision
						placeholder={ __( 'key', 'open-bulk-buying' ) }
						value={ row.key }
						onChange={ ( key ) =>
							onChange( {
								key: cleanKeyInput( key ),
								keyTouched: true,
							} )
						}
					/>
				) }
			</div>
			<div className="openbb-option-row__actions">
				{ row.saved ? (
					<Button
						size="compact"
						variant="tertiary"
						onClick={ () =>
							onChange( { archived: ! row.archived } )
						}
					>
						{ row.archived
							? __( 'Restore', 'open-bulk-buying' )
							: __( 'Archive', 'open-bulk-buying' ) }
					</Button>
				) : (
					<Button
						size="compact"
						icon={ trash }
						/* translators: %s: option position, e.g. "Option 3". */
						label={ sprintf(
							__( 'Remove %s', 'open-bulk-buying' ),
							name
						) }
						onClick={ onRemove }
					/>
				) }
			</div>
		</div>
	);
}

function GroupRow( { row, name, onChange, onRemove } ) {
	return (
		<div className="openbb-option-row is-group">
			<div className="openbb-option-row__label">
				<LocalizedTextControl
					id={ `${ row.id }-label` }
					label={ name }
					hideLabelFromVision
					placeholder={ __( 'Group heading', 'open-bulk-buying' ) }
					value={ row.label }
					onChange={ ( label ) => onChange( { label } ) }
				/>
			</div>
			<div className="openbb-option-row__key">
				<span className="openbb-badge">
					{ __( 'Group', 'open-bulk-buying' ) }
				</span>
			</div>
			<div className="openbb-option-row__actions">
				<Button
					size="compact"
					icon={ trash }
					label={ sprintf(
						/* translators: %s: group position, e.g. "Group 2". */
						__(
							'Remove %s (its options move to the group above)',
							'open-bulk-buying'
						),
						name
					) }
					onClick={ onRemove }
				/>
			</div>
		</div>
	);
}

/**
 * The options of an options field, as one ordered list of options and group
 * headings. An option belongs to the closest heading above it; options above
 * the first heading are ungrouped.
 *
 * @param {Object}   props
 * @param {Array}    props.rows
 * @param {Function} props.onChange Receives the new rows.
 */
export default function OptionsEditor( { rows, onChange } ) {
	const [ focusId, setFocusId ] = useState( null );
	const names = rowNames( rows );

	useEffect( () => {
		if ( focusId ) {
			document.getElementById( `${ focusId }-label` )?.focus();
			setFocusId( null );
		}
	}, [ focusId ] );

	const update = ( id, changes ) =>
		onChange(
			rows.map( ( row ) => {
				if ( row.id !== id ) {
					return row;
				}
				const next = { ...row, ...changes };
				// A new option's key follows its label until the key is edited.
				if (
					next.kind === 'option' &&
					! next.saved &&
					! next.keyTouched &&
					changes.label
				) {
					const taken = new Set(
						rows
							.filter(
								( other ) =>
									other.kind === 'option' && other.id !== id
							)
							.map( ( other ) => other.key )
					);
					next.key = uniqueKey(
						keyFromLabel( next.label.default ),
						taken,
						'option'
					);
				}
				return next;
			} )
		);

	const add = ( row ) => {
		onChange( [ ...rows, row ] );
		setFocusId( row.id );
	};

	return (
		<VStack spacing={ 3 } className="openbb-options-editor">
			{ rows.length > 0 && (
				<SortableList
					items={ rows }
					getKey={ ( row ) => row.id }
					getLabel={ ( row ) => row.label.default || names[ row.id ] }
					onMove={ ( from, to ) =>
						onChange( moveItem( rows, from, to ) )
					}
					itemClassName={ ( row ) =>
						[
							row.kind === 'group' && 'is-group',
							row.archived && 'is-archived',
						]
							.filter( Boolean )
							.join( ' ' )
					}
					renderItem={ ( row ) => {
						const props = {
							row,
							name: names[ row.id ],
							onChange: ( changes ) => update( row.id, changes ),
							onRemove: () =>
								onChange(
									rows.filter(
										( other ) => other.id !== row.id
									)
								),
						};
						return row.kind === 'group' ? (
							<GroupRow { ...props } />
						) : (
							<OptionRow { ...props } />
						);
					} }
				/>
			) }
			<HStack justify="flex-start">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon={ plus }
					onClick={ () => add( newOptionRow() ) }
				>
					{ __( 'Add option', 'open-bulk-buying' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					icon={ plus }
					onClick={ () => add( newGroupRow() ) }
				>
					{ __( 'Add group', 'open-bulk-buying' ) }
				</Button>
			</HStack>
			<p className="openbb-muted">
				{ __(
					'Options belong to the group heading above them, so moving a heading changes which options it holds. Options above the first heading have no group. Archived options stay on members who chose them but can’t be chosen again.',
					'open-bulk-buying'
				) }
			</p>
		</VStack>
	);
}
