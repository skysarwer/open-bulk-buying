<?php
/**
 * One poll as a form. The article holds the `openbb/polls` context (pollId, responded,
 * busy, notice, status, confirmation, confirmed) and the form the `openbb/forms` context
 * its fields use (inc/frontend/fields.php). See OPENBB_Polls_View.
 *
 * Saving shows the status under the form or, with a confirmation, in place of the intro
 * and form until the member goes back to their answers. The confirmation's main action
 * is `back` when there is one (the order page).
 *
 * @var array $view id, title, intro, cycle_name, fields, confirmation, back (url, label)
 *                  or empty, context, form_context.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<article class="openbb-poll" aria-labelledby="<?php echo esc_attr( $view['id'] ); ?>-title" <?php echo wp_interactivity_data_wp_context( $view['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>>
	<header class="openbb-poll__header">
		<h2 class="openbb-poll__title" id="<?php echo esc_attr( $view['id'] ); ?>-title" tabindex="-1"><?php echo esc_html( $view['title'] ); ?></h2>
		<p class="openbb-poll__cycle"><?php echo esc_html( $view['cycle_name'] ); ?></p>
	</header>

	<?php if ( '' !== trim( $view['intro'] ) ) : ?>
		<div class="openbb-poll__intro" data-wp-bind--hidden="context.confirmed"><?php echo wp_kses_post( wpautop( esc_html( $view['intro'] ) ) ); ?></div>
	<?php endif; ?>

	<form class="openbb-poll__form" novalidate <?php echo wp_interactivity_data_wp_context( $view['form_context'], 'openbb/forms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?> data-wp-bind--hidden="context.confirmed" data-wp-on--submit="actions.submit" data-wp-on--input="actions.touch" data-wp-on--change="actions.touch">
		<?php foreach ( $view['fields'] as $field ) : ?>
			<?php echo openbb_render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by openbb_render_field(). ?>
		<?php endforeach; ?>

		<p class="openbb-poll__privacy"><?php echo esc_html( OPENBB_Polls_View::privacy_note() ); ?></p>

		<div class="openbb-poll__notice" data-wp-bind--hidden="!context.notice" hidden>
			<p class="openbb-notice openbb-notice--error" role="alert" data-wp-text="context.notice"></p>
		</div>

		<div class="openbb-poll__actions">
			<button type="submit" class="openbb-button openbb-button--primary" data-wp-bind--disabled="context.busy">
				<span data-wp-bind--hidden="context.responded"><?php esc_html_e( 'Submit answers', 'open-bulk-buying' ); ?></span>
				<span data-wp-bind--hidden="!context.responded"><?php esc_html_e( 'Update answers', 'open-bulk-buying' ); ?></span>
			</button>
			<?php if ( ! $view['confirmation'] ) : ?>
				<p class="openbb-poll__status" role="status" data-wp-text="context.status"></p>
			<?php endif; ?>
		</div>
	</form>

	<?php if ( $view['confirmation'] ) : ?>
		<div class="openbb-poll__confirmation" data-wp-bind--hidden="!context.confirmed" hidden>
			<p class="openbb-notice openbb-notice--success" tabindex="-1" data-wp-text="context.status"></p>
			<div class="openbb-poll__actions">
				<?php if ( $view['back'] ) : ?>
					<a class="openbb-button openbb-button--primary" href="<?php echo esc_url( $view['back']['url'] ); ?>"><?php echo esc_html( $view['back']['label'] ); ?></a>
				<?php endif; ?>
				<button type="button" class="openbb-button openbb-button--secondary" data-wp-on--click="actions.edit">
					<span data-wp-bind--hidden="!context.responded"><?php esc_html_e( 'View or change your answers', 'open-bulk-buying' ); ?></span>
					<span data-wp-bind--hidden="context.responded"><?php esc_html_e( 'Answer the poll', 'open-bulk-buying' ); ?></span>
				</button>
			</div>
		</div>
	<?php endif; ?>
</article>
