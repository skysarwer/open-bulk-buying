<?php
/**
 * The signup form. Rendered by OPENBB_Signup_View, then run through the Interactivity
 * API's server-side processing, so every step starts in its correct state.
 *
 * Regions: the outer div is the `openbb/signup` store (flow state in its context);
 * each <form> holds an `openbb/forms` context with the field values and errors.
 *
 * @var array $view See OPENBB_Signup_View::form_view().
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$openbb_id      = $view['id'];
$openbb_h       = $view['heading_tag'];
$openbb_last    = end( $view['form_steps'] );
$openbb_visible = array_values( array_diff( $view['steps'], array( 'done' ) ) );

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

$openbb_actions = static function ( string $step ) use ( $view, $openbb_last ): string {
	$html = '<div class="openbb-actions">';
	if ( $step === $openbb_last ) {
		$html = $view['captcha'] . $html;
	}
	if ( $step === $openbb_last && '' !== $view['fee'] ) {
		$html = '<p class="openbb-notice openbb-notice--info openbb-signup__fee">' . esc_html( $view['fee'] ) . '</p>' . $html;
	}
	if ( $step === $openbb_last && $view['claim_fields'] ) {
		$claims = '';
		foreach ( $view['claim_fields'] as $field ) {
			$claims .= openbb_render_field( $field );
		}
		$html = '<div class="openbb-fields openbb-signup__fee-claim">' . $claims . '</div>' . $html;
	}
	$html .= sprintf(
		'<button type="submit" class="openbb-button openbb-button--primary" data-wp-bind--disabled="context.busy">%s</button>',
		esc_html( $step === $openbb_last ? $view['submit_label'] : __( 'Continue', 'open-bulk-buying' ) )
	);
	if ( 'details' !== $step ) {
		$html .= sprintf( '<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.back" data-wp-bind--disabled="context.busy">%s</button>', esc_html__( 'Back', 'open-bulk-buying' ) );
	}

	return $html . '</div>';
};
?>
<div
	class="openbb openbb-signup"
	id="<?php echo esc_attr( $openbb_id ); ?>"
	data-wp-interactive="openbb/signup"
	<?php echo wp_interactivity_data_wp_context( $view['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-watch="callbacks.countdown"
>
	<?php if ( '' !== $view['title'] ) : ?>
		<h2 class="openbb-signup__title"><?php echo esc_html( $view['title'] ); ?></h2>
	<?php endif; ?>
	<?php if ( '' !== $view['intro'] ) : ?>
		<p class="openbb-signup__intro"><?php echo esc_html( $view['intro'] ); ?></p>
	<?php endif; ?>

	<?php if ( count( $openbb_visible ) > 1 ) : ?>
		<ol class="openbb-progress" aria-label="<?php esc_attr_e( 'Signup steps', 'open-bulk-buying' ); ?>" data-wp-bind--hidden="context.current.done">
			<?php foreach ( $openbb_visible as $openbb_step ) : ?>
				<li class="openbb-progress__step" data-wp-class--is-current="context.current.<?php echo esc_attr( $openbb_step ); ?>" data-wp-bind--aria-current="context.current.<?php echo esc_attr( $openbb_step ); ?>">
					<?php echo esc_html( $view['labels'][ $openbb_step ] ); ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<div class="openbb-signup__notice" role="alert">
		<p class="openbb-notice openbb-notice--error" data-wp-bind--hidden="!context.notice" data-wp-text="context.notice"></p>
	</div>
	<div class="openbb-signup__status" role="status">
		<p class="openbb-notice openbb-notice--success" data-wp-bind--hidden="!context.status" data-wp-text="context.status"></p>
	</div>

	<form
		class="openbb-form"
		novalidate
		<?php echo wp_interactivity_data_wp_context( $view['form_context'], 'openbb/forms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		data-wp-on--submit="actions.submit"
		data-wp-bind--aria-busy="context.busy"
	>
		<?php echo $openbb_section( 'details' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $openbb_heading( 'details', $view['labels']['details'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( '' !== $view['logged_in_as'] ) : ?>
				<p class="openbb-signup__account">
					<?php
					/* translators: %s: email address. */
					printf( esc_html__( 'You\'re logged in as %s.', 'open-bulk-buying' ), '<strong>' . esc_html( $view['logged_in_as'] ) . '</strong>' );
					if ( $view['has_locked'] ) {
						echo ' ' . esc_html__( 'Details already on your account are shown greyed out.', 'open-bulk-buying' );
					}
					?>
				</p>
			<?php endif; ?>
			<p class="openbb-form__required-note"><?php esc_html_e( 'Fields marked * are required.', 'open-bulk-buying' ); ?></p>

			<div class="openbb-fields openbb-fields--columns">
				<?php
				foreach ( $view['account_fields'] as $openbb_field ) {
					echo openbb_render_field( $openbb_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<div class="openbb-hp" aria-hidden="true">
				<label>
					<?php esc_html_e( 'Leave this empty', 'open-bulk-buying' ); ?>
					<input type="text" name="<?php echo esc_attr( $view['honeypot'] ); ?>" tabindex="-1" autocomplete="off">
				</label>
			</div>

			<?php echo $openbb_actions( 'details' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $view['can_switch'] ) : ?>
				<p class="openbb-signup__login openbb-auth__switch">
					<?php esc_html_e( 'Already have an account?', 'open-bulk-buying' ); ?>
					<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="openbb/auth::actions.showLogin"><?php esc_html_e( 'Log in', 'open-bulk-buying' ); ?></button>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( in_array( 'profile', $view['form_steps'], true ) ) : ?>
			<?php echo $openbb_section( 'profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $openbb_heading( 'profile', $view['labels']['profile'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( $view['profile_fields'] ) : ?>
					<div class="openbb-fields">
						<?php
						foreach ( $view['profile_fields'] as $openbb_field ) {
							echo openbb_render_field( $openbb_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $view['rules_field'] ) : ?>
					<div class="openbb-signup__rules">
						<?php if ( $view['profile_fields'] ) : ?>
							<?php
							printf(
								'<%1$s class="openbb-signup__rules-title">%2$s</%1$s>',
								'h2' === $openbb_h ? 'h3' : 'h4',
								esc_html__( 'Co-op rules', 'open-bulk-buying' )
							);
							?>
						<?php endif; ?>
						<div class="openbb-rules" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Co-op rules', 'open-bulk-buying' ); ?>">
							<?php echo wp_kses_post( $view['rules'] ); ?>
						</div>
						<?php echo openbb_render_field( $view['rules_field'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

				<?php echo $openbb_actions( 'profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		<?php endif; ?>
	</form>

	<?php if ( in_array( 'code', $view['steps'], true ) ) : ?>
		<?php echo $openbb_section( 'code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $openbb_heading( 'code', __( 'Check your email', 'open-bulk-buying' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p id="<?php echo esc_attr( $openbb_id ); ?>-code-intro">
				<?php
				printf(
					/* translators: 1: number of digits, 2: email address. */
					esc_html__( 'We sent a %1$d-digit code to %2$s.', 'open-bulk-buying' ),
					(int) $view['code_length'],
					'<strong data-wp-text="context.email"></strong>'
				);
				echo ' ';
				printf(
					/* translators: %d: minutes. */
					esc_html( _n( 'Enter it below within %d minute to finish signing up.', 'Enter it below within %d minutes to finish signing up.', $view['code_minutes'], 'open-bulk-buying' ) ),
					(int) $view['code_minutes']
				);
				?>
			</p>

			<form
				class="openbb-form openbb-code-form"
				novalidate
				<?php
				echo wp_interactivity_data_wp_context( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'values' => array( 'code' => '' ),
						'errors' => new stdClass(),
					),
					'openbb/forms'
				);
				?>
				data-wp-on--submit="actions.verify"
				data-wp-bind--aria-busy="context.busy"
			>
				<?php echo OPENBB_Frontend::template( 'code-field', array( 'id' => $openbb_id, 'code_length' => $view['code_length'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="openbb-actions">
					<button type="submit" class="openbb-button openbb-button--primary" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Confirm', 'open-bulk-buying' ); ?></button>
					<button type="button" class="openbb-button openbb-button--secondary" data-wp-on--click="actions.resend" data-wp-bind--disabled="!context.canResend">
						<?php esc_html_e( 'Send a new code', 'open-bulk-buying' ); ?>
						<span class="openbb-countdown" data-wp-bind--hidden="context.canResend">
							<?php
							/* translators: %s: number of seconds, updated live. */
							printf( esc_html__( '(in %ss)', 'open-bulk-buying' ), '<span data-wp-text="context.resendIn"></span>' );
							?>
						</span>
					</button>
					<button type="button" class="openbb-button openbb-button--link" data-wp-on--click="actions.back" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Use a different email', 'open-bulk-buying' ); ?></button>
				</div>
				<p class="openbb-form__hint"><?php esc_html_e( 'No email? Check your spam folder, or send a new code.', 'open-bulk-buying' ); ?></p>
			</form>
		</section>
	<?php endif; ?>

	<?php echo $openbb_section( 'done' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $openbb_heading( 'done', __( 'You\'re signed up', 'open-bulk-buying' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<p data-wp-bind--hidden="!context.applicant"><?php esc_html_e( 'Thanks! The co-op will review your application and email you once you\'re approved.', 'open-bulk-buying' ); ?></p>
		<p data-wp-bind--hidden="context.applicant"><?php esc_html_e( 'Welcome to the co-op! You\'re now a member.', 'open-bulk-buying' ); ?></p>
		<p><a class="openbb-button openbb-button--primary" href="<?php echo esc_url( $view['redirect_to'] ); ?>"><?php esc_html_e( 'Continue', 'open-bulk-buying' ); ?></a></p>
	</section>
</div>
