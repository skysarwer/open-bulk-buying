<?php
/**
 * Shown by the login view to someone who is already logged in.
 *
 * @var array $view title, email, continue_url, logout_url.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb openbb-login">
	<?php if ( '' !== $view['title'] ) : ?>
		<h2 class="openbb-login__title"><?php echo esc_html( $view['title'] ); ?></h2>
	<?php endif; ?>
	<p class="openbb-notice openbb-notice--info">
		<?php
		/* translators: %s: email address. */
		printf( esc_html__( 'You\'re logged in as %s.', 'open-bulk-buying' ), '<strong>' . esc_html( $view['email'] ) . '</strong>' );
		?>
	</p>
	<div class="openbb-actions">
		<?php if ( '' !== $view['continue_url'] ) : ?>
			<a class="openbb-button openbb-button--primary" href="<?php echo esc_url( $view['continue_url'] ); ?>"><?php esc_html_e( 'Continue', 'open-bulk-buying' ); ?></a>
		<?php endif; ?>
		<a class="openbb-button openbb-button--link" href="<?php echo esc_url( $view['logout_url'] ); ?>"><?php esc_html_e( 'Log out', 'open-bulk-buying' ); ?></a>
	</div>
</div>
