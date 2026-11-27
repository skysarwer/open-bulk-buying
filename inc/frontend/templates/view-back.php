<?php
/**
 * Back link for standalone views on the order page.
 *
 * @var array $view url, label.
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="openbb-view-back">
	<a href="<?php echo esc_url( $view['url'] ); ?>">
		<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="16" height="16"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
		<?php echo esc_html( $view['label'] ); ?>
	</a>
</p>
