<?php
/**
 * The basket panel: a <dialog> shown as a sidebar on wide screens and as a modal sheet
 * on narrow ones (assets/js/order.js switches between show() and showModal()).
 *
 * Steps, switched in place by state.step:
 * - basket   The lines with steppers, totals and Continue.
 * - finalize Extra questions, donation, financial aid, note, the commitment and submit.
 *            Its fields use the `openbb/forms` context (inc/frontend/fields.php).
 * - done     Confirmation after submitting or saving.
 *
 * @var array $view id, submitted, aid (aid status), questions (HTML), fields, values.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$fields       = $view['fields'];
$aid_decided  = in_array( $view['aid'], array( OPENBB_Order::AID_APPROVED, OPENBB_Order::AID_DECLINED ), true );
$form_context = array(
	'values' => $view['values'],
	'errors' => new stdClass(),
);
?>
<dialog class="openbb-basket" id="<?php echo esc_attr( $view['id'] ); ?>" aria-labelledby="<?php echo esc_attr( $view['id'] ); ?>-title" open data-wp-watch="callbacks.syncPanel" data-wp-on--close="actions.panelClosed" data-wp-on--click="actions.backdropClick">
	<div class="openbb-basket__inner">
		<header class="openbb-basket__header">
			<h2 class="openbb-basket__title" id="<?php echo esc_attr( $view['id'] ); ?>-title" tabindex="-1">
				<span data-wp-bind--hidden="!state.isBasketStep"><?php esc_html_e( 'Your basket', 'open-bulk-buying' ); ?></span>
				<span data-wp-bind--hidden="!state.isFinalizeStep" hidden><?php echo esc_html( $view['submitted'] ? __( 'Order details', 'open-bulk-buying' ) : __( 'Finalize your order', 'open-bulk-buying' ) ); ?></span>
				<span data-wp-bind--hidden="!state.isDoneStep" hidden><?php esc_html_e( 'Thank you!', 'open-bulk-buying' ); ?></span>
			</h2>
			<p class="openbb-basket__save" data-wp-text="state.saveText"></p>
			<button type="button" class="openbb-basket__close" aria-controls="<?php echo esc_attr( $view['id'] ); ?>" data-wp-on--click="actions.closePanel">
				<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
				<span class="openbb-sr-only"><?php esc_html_e( 'Close basket', 'open-bulk-buying' ); ?></span>
			</button>
		</header>

		<div class="openbb-basket__notice" data-wp-bind--hidden="!state.notice" hidden>
			<div class="openbb-notice openbb-notice--error" role="alert">
				<span data-wp-text="state.notice"></span>
				<button type="button" class="openbb-button openbb-button--link" data-wp-bind--hidden="!state.conflict" data-wp-on--click="actions.reload" hidden><?php esc_html_e( 'Reload', 'open-bulk-buying' ); ?></button>
				<button type="button" class="openbb-button openbb-button--link" data-wp-bind--hidden="!state.canRetry" data-wp-on--click="actions.retry" hidden><?php esc_html_e( 'Try again', 'open-bulk-buying' ); ?></button>
			</div>
		</div>

		<?php // Step 1: the basket. ?>
		<div class="openbb-basket__step openbb-basket__step--basket" data-wp-bind--hidden="!state.isBasketStep">
			<p class="openbb-basket__empty" data-wp-bind--hidden="!state.isEmpty"><?php esc_html_e( 'Your basket is empty. Add products from the list to start your order.', 'open-bulk-buying' ); ?></p>
			<ul class="openbb-basket__lines" data-wp-bind--hidden="state.isEmpty">
				<template data-wp-each--item="state.items" data-wp-each-key="context.item.id">
					<li class="openbb-line">
						<div class="openbb-line__info">
							<p class="openbb-line__name" data-wp-text="state.name"></p>
							<p class="openbb-line__price" data-wp-text="state.priceLabel"></p>
						</div>
						<?php echo OPENBB_Frontend::template( 'order-stepper', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
						<p class="openbb-line__total" data-wp-text="state.lineTotal"></p>
						<button type="button" class="openbb-button openbb-button--link openbb-line__remove" data-wp-bind--aria-label="state.removeLabel" data-wp-bind--hidden="state.locked" data-wp-on--click="actions.remove">
							<?php esc_html_e( 'Remove', 'open-bulk-buying' ); ?>
						</button>
					</li>
				</template>
			</ul>

			<?php echo OPENBB_Frontend::template( 'order-totals', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>

			<div class="openbb-basket__actions" data-wp-bind--hidden="state.locked">
				<button type="button" class="openbb-button openbb-button--primary openbb-basket__continue" data-wp-bind--disabled="!state.canFinalize" data-wp-on--click="actions.finalize">
					<?php echo esc_html( $view['submitted'] ? __( 'Review order details', 'open-bulk-buying' ) : __( 'Continue to finalize', 'open-bulk-buying' ) ); ?>
				</button>
			</div>
		</div>

		<?php // Step 2: finalize. ?>
		<form class="openbb-basket__step openbb-basket__step--finalize openbb-finalize" novalidate hidden data-wp-bind--hidden="!state.isFinalizeStep" <?php echo wp_interactivity_data_wp_context( $form_context, 'openbb/forms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?> data-wp-on--submit="actions.submit" data-wp-on--input="actions.syncDetails" data-wp-on--change="actions.syncDetails">
			<button type="button" class="openbb-button openbb-button--link openbb-finalize__back" data-wp-on--click="actions.backToBasket">
				<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="16" height="16"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
				<?php esc_html_e( 'Back to basket', 'open-bulk-buying' ); ?>
			</button>

			<?php if ( '' !== trim( $view['questions'] ) ) : ?>
				<section class="openbb-finalize__section openbb-finalize__questions">
					<?php echo $view['questions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Printed by openbb_order_form_questions callbacks. ?>
				</section>
			<?php endif; ?>

			<section class="openbb-finalize__section">
				<?php echo openbb_render_field( $fields['donation'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
			</section>

			<section class="openbb-finalize__section openbb-finalize__aid">
				<?php if ( $aid_decided ) : ?>
					<p class="openbb-notice">
						<?php
						echo esc_html(
							OPENBB_Order::AID_APPROVED === $view['aid']
								? __( 'Your financial aid request was approved. It is taken off your total.', 'open-bulk-buying' )
								: __( 'Your financial aid request was reviewed and could not be approved this time.', 'open-bulk-buying' )
						);
						?>
					</p>
				<?php else : ?>
					<?php echo openbb_render_field( $fields['aid'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
					<div class="openbb-finalize__aid-details" data-wp-bind--hidden="openbb/forms::!context.values.aid">
						<?php echo openbb_render_field( $fields['aid_reason'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="openbb-finalize__section">
				<?php echo openbb_render_field( $fields['member_note'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
			</section>

			<p class="openbb-notice openbb-finalize__containers" data-wp-bind--hidden="!state.hasContainerTip" data-wp-text="state.containerTip" hidden></p>

			<?php echo OPENBB_Frontend::template( 'order-totals', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>

			<?php if ( isset( $fields['commit'] ) ) : ?>
				<section class="openbb-finalize__section openbb-finalize__commit">
					<?php echo openbb_render_field( $fields['commit'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
				</section>
			<?php endif; ?>

			<div class="openbb-basket__actions">
				<button type="submit" class="openbb-button openbb-button--primary openbb-finalize__submit" data-wp-bind--disabled="state.busy">
					<?php echo esc_html( $view['submitted'] ? __( 'Save changes', 'open-bulk-buying' ) : __( 'Place order', 'open-bulk-buying' ) ); ?>
				</button>
			</div>
		</form>

		<?php // Step 3: done. ?>
		<div class="openbb-basket__step openbb-basket__step--done openbb-done" hidden data-wp-bind--hidden="!state.isDoneStep">
			<p class="openbb-notice openbb-notice--success" data-wp-text="state.doneText"></p>
			<?php echo OPENBB_Frontend::template( 'order-totals', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
			<div class="openbb-basket__actions">
				<button type="button" class="openbb-button openbb-button--primary" data-wp-on--click="actions.backToBasket"><?php esc_html_e( 'View my basket', 'open-bulk-buying' ); ?></button>
				<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.closePanel"><?php esc_html_e( 'Keep browsing', 'open-bulk-buying' ); ?></button>
			</div>
		</div>
	</div>
</dialog>
