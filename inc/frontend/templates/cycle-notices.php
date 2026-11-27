<?php
/**
 * A cycle's open notices on the order page: info (blue) or alert (yellow), each with an
 * optional call-to-action link. See OPENBB_Cycle_Notices_View.
 *
 * @var array $view items: each type, content, cta (text, url) or null, cycle (name to show, or '').
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<aside class="openbb openbb-cycle-notices" aria-label="<?php esc_attr_e( 'Notices from the co-op', 'open-bulk-buying' ); ?>">
	<?php foreach ( $view['items'] as $item ) : ?>
		<div class="openbb-notice openbb-notice--<?php echo esc_attr( $item['type'] ); ?> openbb-cycle-notice">
			<div class="openbb-cycle-notice__text">
				<?php if ( '' !== $item['cycle'] ) : ?>
					<strong class="openbb-cycle-notice__cycle"><?php echo esc_html( $item['cycle'] ); ?></strong>
				<?php endif; ?>
				<p class="openbb-cycle-notice__content"><?php echo nl2br( esc_html( $item['content'] ) ); ?></p>
			</div>
			<?php if ( $item['cta'] ) : ?>
				<a class="openbb-button openbb-cycle-notice__cta" href="<?php echo esc_url( $item['cta']['url'], OPENBB_Cycle_Notices::CTA_PROTOCOLS ); ?>"><?php echo esc_html( $item['cta']['text'] ); ?></a>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</aside>
