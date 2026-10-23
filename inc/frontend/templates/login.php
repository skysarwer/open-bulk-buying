<?php
/**
 * The login form. Rendered by OPENBB_Login_View, then run through the Interactivity API's
 * server-side processing as part of the login/signup view.
 *
 * Steps (context.current): form (password, or email-link with context.link), sent (check
 * your email), confirm (a login link was opened: confirm to log in).
 *
 * @var array $view See OPENBB_Login_View::panel().
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$openbb_id = $view['id'];
$openbb_h  = $view['heading_tag'];

$openbb_heading = static function ( string $step, string $text ) use ( $openbb_id, $openbb_h ): string {
	return sprintf(
		'<%1$s class="openbb-step__title" id="%2$s-%3$s-title" tabindex="-1" data-wp-watch="callbacks.focusStep">%4$s</%1$s>',
		$openbb_h,
		esc_attr( $openbb_id ),
		esc_attr( $step ),
		esc_html( $text )
	);
};

$openbb_section = static function ( string $step ) use ( $openbb_id ): string {
	return sprintf(
		'<section class="openbb-step openbb-step--%1$s" data-step="%1$s" aria-labelledby="%2$s-%1$s-title" data-wp-bind--hidden="!context.current.%1$s">',
		esc_attr( $step ),
		esc_attr( $openbb_id )
	);
};
?>
<div
	class="openbb openbb-login"
	id="<?php echo esc_attr( $openbb_id ); ?>"
	data-wp-interactive="openbb/login"
	<?php echo wp_interactivity_data_wp_context( $view['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-init="callbacks.cleanUrl"
	data-wp-watch="callbacks.countdown"
>
	<?php if ( '' !== $view['title'] ) : ?>
		<h2 class="openbb-login__title"><?php echo esc_html( $view['title'] ); ?></h2>
	<?php endif; ?>
	<?php if ( '' !== $view['intro'] ) : ?>
		<p class="openbb-login__intro"><?php echo esc_html( $view['intro'] ); ?></p>
	<?php endif; ?>

	<div class="openbb-login__notice" role="alert">
		<p class="openbb-notice openbb-notice--error" data-wp-bind--hidden="!context.notice" data-wp-text="context.notice"></p>
	</div>
	<div class="openbb-login__status" role="status">
		<p class="openbb-notice openbb-notice--success" data-wp-bind--hidden="!context.status" data-wp-text="context.status"></p>
	</div>

	<?php echo $openbb_section( 'form' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $openbb_heading( 'form', __( 'Log in', 'open-bulk-buying' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<form
			class="openbb-form"
			novalidate
			<?php echo wp_interactivity_data_wp_context( $view['form_context'], 'openbb/forms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			data-wp-on--submit="actions.submit"
			data-wp-bind--aria-busy="context.busy"
		>
			<p class="openbb-form__hint" data-wp-bind--hidden="!context.link"><?php esc_html_e( 'We\'ll email you a link that logs you in, so you don\'t need a password.', 'open-bulk-buying' ); ?></p>

			<div class="openbb-fields" data-login-fields>
				<?php echo openbb_render_field( $view['fields']['login'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="openbb-fields openbb-login__password" data-wp-bind--hidden="context.link">
				<?php echo openbb_render_field( $view['fields']['password'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo openbb_render_field( $view['fields']['remember'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="openbb-actions">
				<button type="submit" class="openbb-button openbb-button--primary" data-wp-bind--disabled="context.busy">
					<span data-wp-bind--hidden="context.link"><?php esc_html_e( 'Log in', 'open-bulk-buying' ); ?></span>
					<span data-wp-bind--hidden="!context.link"><?php esc_html_e( 'Email me a login link', 'open-bulk-buying' ); ?></span>
				</button>
				<a class="openbb-login__lost" href="<?php echo esc_url( $view['lost_url'] ); ?>" data-wp-bind--hidden="context.link"><?php esc_html_e( 'Forgot your password?', 'open-bulk-buying' ); ?></a>
			</div>

			<?php if ( $view['links'] ) : ?>
				<p class="openbb-login__method">
					<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.toggleMethod" data-wp-bind--disabled="context.busy">
						<span data-wp-bind--hidden="context.link"><?php esc_html_e( 'Email me a login link instead', 'open-bulk-buying' ); ?></span>
						<span data-wp-bind--hidden="!context.link"><?php esc_html_e( 'Log in with a password instead', 'open-bulk-buying' ); ?></span>
					</button>
				</p>
			<?php endif; ?>
		</form>

		<?php if ( $view['can_switch'] ) : ?>
			<p class="openbb-login__signup openbb-auth__switch">
				<?php esc_html_e( 'New to the co-op?', 'open-bulk-buying' ); ?>
				<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="openbb/auth::actions.showSignup"><?php esc_html_e( 'Sign up', 'open-bulk-buying' ); ?></button>
			</p>
		<?php endif; ?>
	</section>

	<?php echo $openbb_section( 'sent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $openbb_heading( 'sent', __( 'Check your email', 'open-bulk-buying' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<p>
			<?php
			printf(
				/* translators: %s: the email address or username entered. */
				esc_html__( 'If there\'s an account for %s, we\'ve emailed it a login link.', 'open-bulk-buying' ),
				'<strong data-wp-text="context.login"></strong>'
			);
			echo ' ';
			printf(
				/* translators: %d: minutes. */
				esc_html( _n( 'The link works once and expires in %d minute.', 'The link works once and expires in %d minutes.', $view['link_minutes'], 'open-bulk-buying' ) ),
				(int) $view['link_minutes']
			);
			?>
		</p>
		<div class="openbb-actions">
			<button type="button" class="openbb-button openbb-button--secondary" data-wp-on--click="actions.resend" data-wp-bind--disabled="!context.canResend">
				<?php esc_html_e( 'Send a new link', 'open-bulk-buying' ); ?>
				<span class="openbb-countdown" data-wp-bind--hidden="context.canResend">
					<?php
					/* translators: %s: number of seconds, updated live. */
					printf( esc_html__( '(in %ss)', 'open-bulk-buying' ), '<span data-wp-text="context.resendIn"></span>' );
					?>
				</span>
			</button>
			<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.back" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Back', 'open-bulk-buying' ); ?></button>
		</div>
		<p class="openbb-form__hint"><?php esc_html_e( 'No email? Check your spam folder, or send a new link.', 'open-bulk-buying' ); ?></p>
	</section>

	<?php echo $openbb_section( 'confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $openbb_heading( 'confirm', __( 'Log in', 'open-bulk-buying' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( '' !== $view['link_email'] ) : ?>
			<p>
				<?php
				/* translators: %s: email address. */
				printf( esc_html__( 'Log in as %s?', 'open-bulk-buying' ), '<strong>' . esc_html( $view['link_email'] ) . '</strong>' );
				?>
			</p>
		<?php endif; ?>
		<div class="openbb-actions">
			<button type="button" class="openbb-button openbb-button--primary" data-autofocus data-wp-on--click="actions.confirm" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Log in', 'open-bulk-buying' ); ?></button>
			<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.back" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Cancel', 'open-bulk-buying' ); ?></button>
		</div>
	</section>
</div>
