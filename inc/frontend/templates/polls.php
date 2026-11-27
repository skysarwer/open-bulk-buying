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
		<?php echo OPENBB_Frontend::template( 'view-back', $view['back'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
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
