<?php
/**
 * Poll invitations on the order page: a card for each open poll the member hasn't
 * answered, and links to change the ones they have. Plain links, no script.
 * See OPENBB_Polls_View::cards().
 *
 * @var array $view cards and answered: lists of title, intro, url.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;
?>
<aside class="openbb openbb-poll-cards" aria-label="<?php esc_attr_e( 'Co-op polls', 'open-bulk-buying' ); ?>">
	<?php foreach ( $view['cards'] as $card ) : ?>
		<div class="openbb-poll-card">
			<div class="openbb-poll-card__text">
				<p class="openbb-poll-card__title"><?php echo esc_html( $card['title'] ); ?></p>
				<?php if ( '' !== $card['intro'] ) : ?>
					<p class="openbb-poll-card__intro"><?php echo esc_html( $card['intro'] ); ?></p>
				<?php endif; ?>
			</div>
			<a class="openbb-button openbb-poll-card__link" href="<?php echo esc_url( $card['url'] ); ?>">
				<?php esc_html_e( 'Answer the poll', 'open-bulk-buying' ); ?>
				<span class="openbb-sr-only"><?php echo esc_html( $card['title'] ); ?></span>
			</a>
		</div>
	<?php endforeach; ?>

	<?php if ( $view['answered'] ) : ?>
		<ul class="openbb-poll-cards__answered">
			<?php foreach ( $view['answered'] as $card ) : ?>
				<li>
					<?php
					printf(
						/* translators: %s: poll title. */
						esc_html__( 'You answered “%s”.', 'open-bulk-buying' ),
						esc_html( $card['title'] )
					);
					?>
					<a href="<?php echo esc_url( $card['url'] ); ?>"><?php esc_html_e( 'Change your answers', 'open-bulk-buying' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</aside>
