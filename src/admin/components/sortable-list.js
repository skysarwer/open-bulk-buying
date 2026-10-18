import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronUp } from '@wordpress/icons';

/**
 * A copy of `items` with the item at `from` moved to `to`.
 *
 * @param {Array}  items
 * @param {number} from
 * @param {number} to
 * @return {Array} Reordered items.
 */
export function moveItem( items, from, to ) {
	const next = [ ...items ];
	const [ item ] = next.splice( from, 1 );
	next.splice( to, 0, item );
	return next;
}

/**
 * An ordered list whose items move with up/down buttons, and the move is
 * announced to screen readers. The buttons stay focusable when disabled, so
 * keyboard focus follows an item to the top or bottom of the list.
 *
 * @param {Object}   props
 * @param {Array}    props.items
 * @param {Function} props.getKey        item => stable React key.
 * @param {Function} props.getLabel      item => name used in button labels.
 * @param {Function} props.onMove        ( from, to ) => void.
 * @param {Function} props.renderItem    ( item, index ) => Element.
 * @param {Function} props.itemClassName item => extra class names.
 * @param {boolean}  props.disabled
 * @param {string}   props.className
 */
export default function SortableList( {
	items,
	getKey,
	getLabel,
	onMove,
	renderItem,
	itemClassName = () => '',
	disabled = false,
	className = '',
} ) {
	const move = ( from, to ) => {
		onMove( from, to );
		speak(
			sprintf(
				/* translators: 1: item name, 2: new position, 3: number of items. */
				__(
					'%1$s moved to position %2$d of %3$d.',
					'open-bulk-buying'
				),
				getLabel( items[ from ] ),
				to + 1,
				items.length
			)
		);
	};

	return (
		<ol className={ `openbb-sortable ${ className }` }>
			{ items.map( ( item, index ) => {
				const label = getLabel( item );
				return (
					<li
						key={ getKey( item ) }
						className={ `openbb-sortable__item ${ itemClassName(
							item
						) }` }
					>
						<div className="openbb-sortable__moves">
							<Button
								size="small"
								icon={ chevronUp }
								label={ sprintf(
									/* translators: %s: item name. */
									__( 'Move %s up', 'open-bulk-buying' ),
									label
								) }
								disabled={ disabled || index === 0 }
								accessibleWhenDisabled
								onClick={ () => move( index, index - 1 ) }
							/>
							<Button
								size="small"
								icon={ chevronDown }
								label={ sprintf(
									/* translators: %s: item name. */
									__( 'Move %s down', 'open-bulk-buying' ),
									label
								) }
								disabled={
									disabled || index === items.length - 1
								}
								accessibleWhenDisabled
								onClick={ () => move( index, index + 1 ) }
							/>
						</div>
						<div className="openbb-sortable__content">
							{ renderItem( item, index ) }
						</div>
					</li>
				);
			} ) }
		</ol>
	);
}
