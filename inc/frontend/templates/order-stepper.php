<?php
/**
 * Quantity stepper: [−] [input] [+]. Used on catalog rows and basket lines; the row's
 * product comes from the context (`id`, or `item.id` inside the basket list).
 *
 * The input is the control: a spinbutton with arrow, Page Up/Down, Home and End keys,
 * labelled with the product name. The − and + buttons are for pointer users and stay
 * out of the tab order (tabindex -1) so keyboard users don't tab through three stops
 * per product, but they keep accessible names for touch screen readers.
 *
 * @var array $view Unused.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb-stepper" data-wp-class--is-active="state.inBasket">
	<button type="button" class="openbb-stepper__button openbb-stepper__button--decrement" tabindex="-1" data-wp-bind--aria-label="state.decLabel" data-wp-bind--disabled="!state.canDecrement" data-wp-on--click="actions.decrement">
		<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18"><path d="M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"/></svg>
	</button>
	<input
		type="text"
		class="openbb-stepper__input"
		inputmode="numeric"
		pattern="[0-9]*"
		autocomplete="off"
		role="spinbutton"
		aria-valuemin="0"
		data-wp-bind--value="state.qty"
		data-wp-bind--aria-valuenow="state.qty"
		data-wp-bind--aria-valuemax="state.max"
		data-wp-bind--aria-label="state.qtyLabel"
		data-wp-bind--readonly="state.locked"
		data-wp-on--keydown="actions.stepKey"
		data-wp-on--input="actions.typeQty"
		data-wp-on--change="actions.setQty"
		data-wp-on--focus="actions.selectQty"
	>
	<button type="button" class="openbb-stepper__button openbb-stepper__button--increment" tabindex="-1" data-wp-bind--aria-label="state.incLabel" data-wp-bind--disabled="!state.canIncrement" data-wp-on--click="actions.increment">
		<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18"><path d="M5 12h14M12 5v14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"/></svg>
	</button>
</div>
