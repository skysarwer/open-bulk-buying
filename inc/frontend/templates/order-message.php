<?php
/**
 * Shown by the order form when there's nothing to order (logged out, not a member,
 * ordering closed, receipt unavailable).
 *
 * @var array $view id, title, text, optional back link.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb openbb-order-message" id="<?php echo esc_attr( $view['id'] ); ?>">
	<?php if ( ! empty( $view['back'] ) ) : ?>
		<?php echo OPENBB_Frontend::template( 'view-back', $view['back'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
	<?php endif; ?>
	<p class="openbb-notice openbb-notice--info"><?php echo esc_html( $view['text'] ); ?></p>
</div>
