<?php
/**
 * The polls view: every poll open to the member as a form, or a message when there are
 * none. See OPENBB_Polls_View.
 *
 * @var array $view id, notice (why a requested poll can't be answered, or ''), back
 *                  (url, label) or empty, polls (for the poll template), empty (text
 *                  shown when there are no polls).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="openbb openbb-polls" id="<?php echo esc_attr( $view['id'] ); ?>"<?php echo $view['polls'] ? ' data-wp-interactive="openbb/polls"' : ''; ?>>
	<?php if ( $view['back'] ) : ?>
		<p class="openbb-polls__back">
			<a href="<?php echo esc_url( $view['back']['url'] ); ?>">
				<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="16" height="16"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
				<?php echo esc_html( $view['back']['label'] ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['notice'] ) : ?>
		<p class="openbb-notice openbb-notice--info"><?php echo esc_html( $view['notice'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! $view['polls'] ) : ?>
		<p class="openbb-notice openbb-notice--info"><?php echo esc_html( $view['empty'] ); ?></p>
	<?php endif; ?>

	<?php foreach ( $view['polls'] as $poll ) : ?>
		<?php echo OPENBB_Frontend::template( 'poll', $poll ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
	<?php endforeach; ?>
</div>
