<?php
/**
 * A static notice in place of the signup form (status, signups closed).
 *
 * @var array $view title, message (escaped HTML), continue_url, can_switch (optional:
 *                  show a button to switch to the login form).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb openbb-signup">
	<?php if ( '' !== $view['title'] ) : ?>
		<h2 class="openbb-signup__title"><?php echo esc_html( $view['title'] ); ?></h2>
	<?php endif; ?>
	<p class="openbb-notice openbb-notice--info"><?php echo $view['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></p>
	<?php if ( '' !== $view['continue_url'] ) : ?>
		<p><a class="openbb-button" href="<?php echo esc_url( $view['continue_url'] ); ?>"><?php esc_html_e( 'Continue', 'open-bulk-buying' ); ?></a></p>
	<?php endif; ?>
	<?php if ( ! empty( $view['can_switch'] ) ) : ?>
		<p class="openbb-signup__login openbb-auth__switch">
			<?php esc_html_e( 'Already have an account?', 'open-bulk-buying' ); ?>
			<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="openbb/auth::actions.showLogin"><?php esc_html_e( 'Log in', 'open-bulk-buying' ); ?></button>
		</p>
	<?php endif; ?>
</div>
