<?php
/**
 * Shown by the order form when there's nothing to order (logged out, not a member,
 * ordering closed).
 *
 * @var array $view title, text.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb openbb-order-message">
	<h2 class="openbb-order-message__title"><?php echo esc_html( $view['title'] ); ?></h2>
	<p class="openbb-notice openbb-notice--info"><?php echo esc_html( $view['text'] ); ?></p>
</div>
