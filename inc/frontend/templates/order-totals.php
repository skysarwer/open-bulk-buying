<?php
/**
 * Order totals, shown under the basket, in the Finalize step and after submitting.
 * Approved aid comes off the goods; a pending request is only noted.
 *
 * @var array $view Unused.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb-totals" data-wp-bind--hidden="state.isEmpty">
<dl class="openbb-totals__list">
	<div class="openbb-totals__row">
		<dt data-wp-text="state.countLabel"></dt>
		<dd data-wp-text="state.subtotalText"></dd>
	</div>
	<div class="openbb-totals__row" data-wp-bind--hidden="!state.hasCredit">
		<dt><?php esc_html_e( 'Own containers', 'open-bulk-buying' ); ?></dt>
		<dd data-wp-text="state.creditText"></dd>
	</div>
	<div class="openbb-totals__row" data-wp-bind--hidden="!state.hasAidApproved">
		<dt><?php esc_html_e( 'Financial aid', 'open-bulk-buying' ); ?></dt>
		<dd data-wp-text="state.aidText"></dd>
	</div>
	<div class="openbb-totals__row" data-wp-bind--hidden="!state.hasDonation">
		<dt><?php esc_html_e( 'Donation', 'open-bulk-buying' ); ?></dt>
		<dd data-wp-text="state.donationText"></dd>
	</div>
	<div class="openbb-totals__row" data-wp-bind--hidden="!state.hasMembershipFee">
		<dt><?php esc_html_e( 'Membership fee', 'open-bulk-buying' ); ?></dt>
		<dd data-wp-text="state.membershipFeeText"></dd>
	</div>
	<div class="openbb-totals__row openbb-totals__row--total">
		<dt><?php esc_html_e( 'Total to pay at pickup', 'open-bulk-buying' ); ?></dt>
		<dd data-wp-text="state.totalText"></dd>
	</div>
</dl>
<p class="openbb-totals__note" data-wp-bind--hidden="!state.membershipPending"><?php esc_html_e( 'Your membership payment claim is awaiting staff verification. The fee has not been added again.', 'open-bulk-buying' ); ?></p>
<p class="openbb-totals__note" data-wp-bind--hidden="!state.hasAidPending"><?php esc_html_e( 'You asked for financial aid. If it is approved, the co-op will tell you how much comes off your total.', 'open-bulk-buying' ); ?></p>
</div>
