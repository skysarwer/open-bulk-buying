<?php
/**
 * Member-facing transactional order email, sent after committed service writes.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Order_Emails {

	public static function on_saved( OPENBB_Order $order, ?OPENBB_Order $before ): void {
		if ( OPENBB_Order::STATUS_DRAFT === $order->status ) {
			return;
		}
		if ( OPENBB_Order::STATUS_SUBMITTED === $order->status && ( ! $before || OPENBB_Order::STATUS_DRAFT === $before->status ) ) {
			$event = 'created';
		} elseif ( $before && $order->status !== $before->status && in_array( $order->status, array( 'prepaid', 'cancelled', 'fulfilled' ), true ) ) {
			if ( OPENBB_Order::STATUS_DRAFT === $before->status ) {
				return;
			}
			$event = $order->status;
		} elseif ( $before && in_array( $order->status, OPENBB_Order::COMMITTED_STATUSES, true ) ) {
			$event = 'updated';
		} else {
			return;
		}

		$switched = switch_to_locale( self::locale( $order ) );
		try {
			$changes = 'updated' === $event ? self::changes( $before, $order ) : array();
			if ( 'updated' === $event && ! $changes ) {
				return;
			}
			$user = get_userdata( $order->user_id );
			$mail = OPENBB_Emails::render( 'order-event', compact( 'order', 'event', 'changes' ) );
			$sent = $user && is_email( $user->user_email ) && wp_mail(
				$user->user_email,
				$mail['subject'],
				$mail['message'],
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
			if ( ! $sent ) {
				// A mail failure must not undo or invite a retry of a committed payment.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[open-bulk-buying] Order %d revision %d %s email could not be sent on site %d.', $order->id, $order->revision, $event, get_current_blog_id() ) );
				do_action( 'openbb_order_email_failed', $order, $event );
			}
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	private static function locale( OPENBB_Order $order ): string {
		$locales = array_unique( array_merge( array( get_locale(), 'en_US' ), get_available_languages(), array( 'fr_CA' ) ) );
		foreach ( $locales as $locale ) {
			if ( strtolower( $locale ) === strtolower( $order->language ) ) {
				return $locale;
			}
		}
		foreach ( $locales as $locale ) {
			if ( strtolower( strtok( $locale, '_' ) ) === $order->language ) {
				return $locale;
			}
		}
		return get_locale();
	}

	/** @return array<string, string> */
	public static function totals( OPENBB_Order $order ): array {
		return array(
			__( 'Subtotal', 'open-bulk-buying' )         => openbb_format_money( $order->subtotal, $order->currency ),
			__( 'Container credit', 'open-bulk-buying' ) => openbb_format_money( -$order->container_credit, $order->currency ),
			__( 'Donation', 'open-bulk-buying' )         => openbb_format_money( $order->donation, $order->currency ),
			__( 'Financial aid', 'open-bulk-buying' )    => openbb_format_money( -$order->aid_approved, $order->currency ),
			__( 'Total', 'open-bulk-buying' )            => openbb_format_money( $order->total, $order->currency ),
		);
	}

	private static function line_summary( OPENBB_Order_Line $line, string $currency ): string {
		return implode(
			'; ',
			array(
				number_format_i18n( $line->get_quantity(), floor( $line->get_quantity() ) === $line->get_quantity() ? 0 : 3 ) . ' x ' . $line->get_unit_label(),
				__( 'Unit price', 'open-bulk-buying' ) . ': ' . openbb_format_money( $line->unit_price + $line->container_charge, $currency ),
				__( 'Own container', 'open-bulk-buying' ) . ': ' . ( $line->own_container ? __( 'Yes', 'open-bulk-buying' ) : __( 'No', 'open-bulk-buying' ) ),
				__( 'Container credit', 'open-bulk-buying' ) . ': ' . openbb_format_money( $line->calculate_container_credit(), $currency ),
				__( 'Line total', 'open-bulk-buying' ) . ': ' . openbb_format_money( $line->calculate_total() - $line->calculate_container_credit(), $currency ),
			)
		);
	}

	/** @return array<int, array{0: string, 1: string, 2: string}> */
	private static function changes( OPENBB_Order $before, OPENBB_Order $order ): array {
		$old = array();
		$new = array();
		foreach ( $before->get_lines() as $line ) {
			if ( $line->get_quantity() > 0 ) {
				$old[ $line->id ] = $line;
			}
		}
		foreach ( $order->get_lines() as $line ) {
			if ( $line->get_quantity() > 0 ) {
				$new[ $line->id ] = $line;
			}
		}
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $id ) {
			$from = isset( $old[ $id ] ) ? self::line_summary( $old[ $id ], $before->currency ) : '';
			$to   = isset( $new[ $id ] ) ? self::line_summary( $new[ $id ], $order->currency ) : '';
			if ( $from !== $to ) {
				$line = $new[ $id ] ?? $old[ $id ];
				$changes[] = array( trim( $line->product_name . ' ' . $line->brand ), $from, $to );
			}
		}
		$old_fields = self::totals( $before );
		$new_fields = self::totals( $order );
		$note_label = __( 'Member note', 'open-bulk-buying' );
		$aid_label  = __( 'Financial aid request', 'open-bulk-buying' );
		$old_fields[ $note_label ] = $before->member_note;
		$new_fields[ $note_label ] = $order->member_note;
		$old_fields[ $aid_label ]  = OPENBB_Order::aid_status_labels()[ $before->aid_status ];
		$new_fields[ $aid_label ]  = OPENBB_Order::aid_status_labels()[ $order->aid_status ];
		foreach ( $new_fields as $label => $value ) {
			if ( $old_fields[ $label ] !== $value ) {
				$changes[] = array( $label, $old_fields[ $label ], $value );
			}
		}
		return $changes;
	}
}
