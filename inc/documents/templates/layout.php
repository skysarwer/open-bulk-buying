<?php
/**
 * Standalone printable page around one or more document pages.
 *
 * @package Open_Bulk_Buying
 *
 * @var array $vars {title, type, pages, paper, css, nonce, autoprint, lang}
 */

defined( 'ABSPATH' ) || exit;

$openbb_count = count( $vars['pages'] );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $vars['lang'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $vars['title'] ); ?></title>
<style nonce="<?php echo esc_attr( $vars['nonce'] ); ?>">
@page { size: <?php echo 'a4' === $vars['paper'] ? 'A4' : 'letter'; ?>; margin: 12mm; }
<?php echo $vars['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled stylesheet. ?>
</style>
</head>
<body class="openbb-document openbb-document--<?php echo esc_attr( $vars['type'] ); ?>">
<div class="openbb-document__toolbar">
	<table role="presentation"><tr>
		<td><strong><?php echo esc_html( $vars['title'] ); ?></strong></td>
		<td class="openbb-document__toolbar-actions">
			<?php if ( $openbb_count ) : ?>
				<button type="button" id="openbb-print"><?php esc_html_e( 'Print', 'open-bulk-buying' ); ?></button>
			<?php endif; ?>
		</td>
	</tr></table>
</div>
<?php if ( ! $openbb_count ) : ?>
	<p class="openbb-document__empty"><?php esc_html_e( 'No orders match. Order slips are printed for submitted, prepaid and fulfilled orders.', 'open-bulk-buying' ); ?></p>
<?php else : ?>
	<div class="openbb-document__sheet">
		<?php
		foreach ( $vars['pages'] as $openbb_page ) {
			echo $openbb_page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the page template.
		}
		?>
	</div>
<?php endif; ?>
<script nonce="<?php echo esc_attr( $vars['nonce'] ); ?>">
( function () {
	var button = document.getElementById( 'openbb-print' );
	if ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	}
	<?php if ( $vars['autoprint'] && $openbb_count ) : ?>
	window.addEventListener( 'load', function () {
		window.print();
	} );
	<?php endif; ?>
}() );
</script>
</body>
</html>
