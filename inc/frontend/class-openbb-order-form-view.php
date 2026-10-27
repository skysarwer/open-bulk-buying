<?php
/**
 * The member order form: [openbb_order_form].
 *
 * Active members browse the catalog of a cycle that is taking orders, fill a basket
 * and submit it. The basket is the member's order in that cycle: it's autosaved as a
 * draft (drafts reserve no stock) and submitted from the Finalize step, which also asks
 * for an optional donation, a financial aid request, a note and the member's commitment
 * to pick up and pay. While ordering is open the member can keep changing it.
 *
 * Layout: a top bar, the catalog and the basket panel. On wide screens the basket is a
 * sidebar the member can close (the catalog then shows two columns); on narrow screens
 * it's a modal sheet opened from the top bar or the checkout bar at the bottom.
 *
 * Templates: order-form (shell), order-product (catalog row), order-stepper (quantity
 * control), order-basket (panel), order-message (when there's nothing to order).
 * Store: assets/js/order.js (`openbb/order`). Styles: src/sass/frontend/order/.
 *
 * Extension point: the `openbb_order_form_questions` action prints extra questions
 * (e.g. polls) into the Finalize step.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Order_Form_View {

	const ID = 'openbb-order';

	/** At or below this many units available, a product shows how many are left. */
	const LOW_STOCK = 5;

	/**
	 * auth: which form a logged-out visitor sees first, login or signup (they can switch).
	 */
	public static function defaults(): array {
		return array(
			'auth' => 'login',
		);
	}

	public static function render( array $args = array() ): string {
		$args = array_merge( self::defaults(), array_intersect_key( $args, self::defaults() ) );

		OPENBB_Frontend::no_cache();
		OPENBB_Frontend::enqueue_style();

		if ( ! is_user_logged_in() ) {
			return self::auth( 'signup' === $args['auth'] ? 'signup' : 'login' );
		}

		$user_id = get_current_user_id();
		$member  = OPENBB_Member::get( $user_id );
		if ( ! $member || ! $member->is_active() || ! current_user_can( 'openbb_place_orders' ) ) {
			return self::message(
				__( 'Order', 'open-bulk-buying' ),
				$member && $member->is_applicant()
					? __( 'Your membership application is being reviewed. You can order once it is approved.', 'open-bulk-buying' )
					: __( 'Only active co-op members can place orders.', 'open-bulk-buying' )
			);
		}

		$cycles = OPENBB_Member_Orders::open_cycles();
		if ( ! $cycles ) {
			$next = OPENBB_Member_Orders::next_cycle();
			return self::message(
				__( 'Ordering is closed', 'open-bulk-buying' ),
				$next
					/* translators: %s: date and time. */
					? sprintf( __( 'The next ordering period opens %s.', 'open-bulk-buying' ), self::local_datetime( $next->opens_at ) )
					: __( 'There is no ordering period open right now. Check back soon.', 'open-bulk-buying' )
			);
		}

		$cycle = $cycles[0];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of which open cycle to show.
		$requested = isset( $_GET['cycle'] ) ? absint( $_GET['cycle'] ) : 0;
		foreach ( $cycles as $open ) {
			if ( $open->id === $requested ) {
				$cycle = $open;
			}
		}

		return self::form( $cycle, $cycles, $member, OPENBB_Orders::get_for_member( $cycle->id, $user_id ) );
	}

	private static function form( OPENBB_Cycle $cycle, array $cycles, OPENBB_Member $member, ?OPENBB_Order $order ): string {
		if ( $order && OPENBB_Order::STATUS_CANCELLED === $order->status ) {
			return OPENBB_Frontend::template(
				'order-cancelled',
				array(
					'id'         => self::ID,
					'order'      => $order,
					'cycle_name' => $cycle->name,
					'cycles'     => count( $cycles ) > 1 ? self::cycle_links( $cycles, $cycle ) : array(),
				)
			);
		}
		OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_ORDER ) );
		OPENBB_Frontend::forms_state();

		$order_state = OPENBB_Member_Orders::state( $order, $cycle );
		$sections    = OPENBB_Catalog::sections();

		$catalog = array();
		foreach ( $sections as $section ) {
			foreach ( $section['products'] as $product ) {
				$catalog[ $product->id ] = $product;
			}
		}
		$limits = OPENBB_Member_Orders::limits( array_values( $catalog ), $order );

		$products = array();
		foreach ( $catalog as $product ) {
			$products[ $product->id ] = array(
				'name'   => $product->get_name(),
				'unit'   => $product->get_unit_label(),
				'price'  => $product->price,
				'charge' => $product->container_charge,
				'max'    => $limits[ $product->id ] ?? 0,
			);
		}
		$items = array();
		foreach ( $order_state['lines'] as $line ) {
			$id = $line['product_id'];
			// What's on the order keeps the price it was added at; products no longer
			// listed can only be reduced.
			$products[ $id ] = array(
				'name'   => $products[ $id ]['name'] ?? $line['name'],
				'unit'   => $line['unit'],
				'price'  => $line['unit_price'],
				'charge' => $line['container_charge'],
				'max'    => max( $products[ $id ]['max'] ?? 0, $line['qty'] ),
			);
			$items[] = array(
				'id'  => $id,
				'qty' => $line['qty'],
				'own' => $line['own_container'],
			);
		}

		self::state( $cycle, $order_state, $products, $items );

		$submitted = OPENBB_Order::STATUS_SUBMITTED === $order_state['status'];
		$digits    = openbb_currency_digits();
		$pickup    = self::pickup_text( $cycle );

		return OPENBB_Frontend::process(
			OPENBB_Frontend::template(
				'order-form',
				array(
					'id'          => self::ID,
					'welcome'     => sprintf(
						/* translators: %s: member's first name. */
						__( 'Welcome, %s', 'open-bulk-buying' ),
						'' !== trim( (string) $member->first_name ) ? $member->first_name : $member->display_name
					),
					'cycle_name'  => $cycle->name,
					/* translators: %s: date and time. */
					'closes'      => sprintf( __( 'Orders close %s', 'open-bulk-buying' ), self::local_datetime( $cycle->closes_at ) ),
					'cycles'      => count( $cycles ) > 1 ? self::cycle_links( $cycles, $cycle ) : array(),
					'staff_notice' => OPENBB_Cycle::STATUS_SETUP === $cycle->status && current_user_can( 'openbb_edit_orders' )
						? __( 'Staff preview: your role lets you place and edit orders during Setup, before ordering opens to members.', 'open-bulk-buying' )
						: '',
					'notice'       => self::status_notice( $order, $order_state, $cycle ),
					'sections'    => self::sections( $sections ),
					'basket'      => array(
						'id'        => self::ID . '-basket',
						'submitted' => $submitted,
						'aid'       => $order_state['aid_status'],
						'questions' => self::questions( $cycle, $order ),
						'fields'    => self::finalize_fields( $order_state, $submitted, $digits, $pickup ),
						'values'    => array(
							'donation'    => self::money_input( $order_state['donation'], $digits ),
							'aid'         => OPENBB_Order::AID_NONE !== $order_state['aid_status'],
							'aid_reason'  => $order_state['aid_reason'],
							'member_note' => $order_state['member_note'],
							'commit'      => false,
						),
					),
				)
			)
		);
	}

	/**
	 * Client state for `openbb/order`. Closures are derived state evaluated during
	 * server rendering; assets/js/order.js defines the same getters.
	 */
	private static function state( OPENBB_Cycle $cycle, array $order_state, array $products, array $items ): void {
		$basket = array();
		foreach ( $items as $item ) {
			$basket[ $item['id'] ] = $item;
		}
		$messages = self::messages();
		$currency = openbb_currency();

		$row     = static function (): int {
			$context = wp_interactivity_get_context();

			return (int) ( $context['item']['id'] ?? $context['id'] ?? 0 );
		};
		$qty     = static function () use ( $row, $basket ): int {
			return (int) ( $basket[ $row() ]['qty'] ?? 0 );
		};
		$product = static function () use ( $row, $products ): array {
			return $products[ $row() ] ?? array(
				'name'   => '',
				'unit'   => '',
				'price'  => 0,
				'charge' => 0,
				'max'    => 0,
			);
		};
		$money   = static function ( int $minor ) use ( $currency ): string {
			return openbb_format_money( $minor, $currency );
		};
		$totals  = array(
			'count'    => 0,
			'subtotal' => 0,
			'credit'   => 0,
			'savings'  => 0,
			'units'    => 0,
		);
		foreach ( $items as $item ) {
			$price               = $products[ $item['id'] ] ?? array(
				'price'  => 0,
				'charge' => 0,
			);
			$totals['count']    += $item['qty'];
			$totals['subtotal'] += $item['qty'] * ( $price['price'] + $price['charge'] );
			$totals['credit']   += $item['own'] ? $item['qty'] * $price['charge'] : 0;
			if ( $price['charge'] > 0 && ! $item['own'] ) {
				$totals['savings'] += $item['qty'] * $price['charge'];
				$totals['units']   += $item['qty'];
			}
		}
		$goods = max( 0, $totals['subtotal'] - $totals['credit'] - $order_state['aid_approved'] );
		$count = sprintf( _n( '%d item', '%d items', $totals['count'], 'open-bulk-buying' ), $totals['count'] );

		wp_interactivity_state(
			'openbb/order',
			array(
				'restUrl'        => rest_url( OPENBB_REST_Controller::API_NAMESPACE . '/order' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'cycleId'        => $cycle->id,
				'panelId'        => self::ID . '-basket',
				'locale'         => str_replace( '_', '-', determine_locale() ),
				'currency'       => $currency,
				'digits'         => openbb_currency_digits( $currency ),
				'lowStock'       => self::LOW_STOCK,
				'orderId'        => $order_state['id'],
				'status'         => $order_state['status'],
				'revision'       => $order_state['revision'],
				'editable'       => $order_state['editable'],
				'aidStatus'      => $order_state['aid_status'],
				'aidApproved'    => $order_state['aid_approved'],
				'donation'       => $order_state['donation'],
				'membershipFee'  => $order_state['membership_fee'],
				'membershipPending' => $order_state['membership_pending'],
				'products'       => (object) $products,
				'items'          => $items,
				'step'           => 'basket',
				'wide'           => true,
				'sidebarOpen'    => true,
				'drawerOpen'     => false,
				'saveStatus'     => '',
				'notice'         => '',
				'conflict'       => false,
				'announcement'   => '',
				'messages'       => $messages,

				// Per row (product card or basket line).
				'qty'            => $qty,
				'max'            => static function () use ( $product ): int {
					return (int) $product()['max'];
				},
				'inBasket'       => static function () use ( $qty ): bool {
					return $qty() > 0;
				},
				'canDecrement'   => static function () use ( $qty, $order_state ): bool {
					return $order_state['editable'] && $qty() > 0;
				},
				'canIncrement'   => static function () use ( $qty, $product, $order_state ): bool {
					return $order_state['editable'] && $qty() < $product()['max'];
				},
				'lineTotal'      => static function () use ( $qty, $product, $money ): string {
					$price = $product();

					return $money( $qty() * ( $price['price'] + $price['charge'] ) );
				},
				'stockNote'      => static function () use ( $qty, $product, $messages ): string {
					$max = (int) $product()['max'];
					if ( $max <= 0 && $qty() <= 0 ) {
						return $messages['soldOut'];
					}

					return $max <= self::LOW_STOCK && $max > 0 ? sprintf( $messages['lowStock'], $max ) : '';
				},
				'isSoldOut'      => static function () use ( $qty, $product ): bool {
					return (int) $product()['max'] <= 0 && $qty() <= 0;
				},
				'name'           => static function () use ( $product ): string {
					return $product()['name'];
				},
				'priceLabel'     => static function () use ( $product, $money, $messages ): string {
					$price = $product();

					return sprintf( $messages['priceLabel'], $money( $price['price'] + $price['charge'] ), $price['unit'] );
				},
				'qtyLabel'       => static function () use ( $product, $messages ): string {
					return sprintf( $messages['qtyLabel'], $product()['name'] );
				},
				'incLabel'       => static function () use ( $product, $messages ): string {
					return sprintf( $messages['incLabel'], $product()['name'] );
				},
				'decLabel'       => static function () use ( $product, $messages ): string {
					return sprintf( $messages['decLabel'], $product()['name'] );
				},
				'removeLabel'    => static function () use ( $product, $messages ): string {
					return sprintf( $messages['removeLabel'], $product()['name'] );
				},

				// Totals and panel.
				'count'          => $totals['count'],
				'countLabel'     => $count,
				'hasItems'       => $totals['count'] > 0,
				'isEmpty'        => 0 === $totals['count'],
				'subtotalText'   => $money( $totals['subtotal'] ),
				'hasContainerTip' => $totals['savings'] > 0,
				'containerTip'   => sprintf( $messages['containerTip'], $money( $totals['savings'] ), $totals['units'] ),
				'hasCredit'      => $totals['credit'] > 0,
				'creditText'     => $money( -$totals['credit'] ),
				'hasAidApproved' => $order_state['aid_approved'] > 0,
				'aidText'        => $money( -min( $order_state['aid_approved'], $totals['subtotal'] - $totals['credit'] ) ),
				'hasDonation'    => $order_state['donation'] > 0,
				'donationText'   => $money( $order_state['donation'] ),
				'hasMembershipFee' => $order_state['membership_fee'] > 0 && $totals['count'] > 0,
				'membershipFeeText' => $money( $order_state['membership_fee'] ),
				'totalText'      => $money( $goods + $order_state['donation'] + ( $totals['count'] > 0 ? $order_state['membership_fee'] : 0 ) ),
				'isSubmitted'    => OPENBB_Order::STATUS_SUBMITTED === $order_state['status'],
				'locked'         => ! $order_state['editable'],
				'canFinalize'    => $order_state['editable'] && $totals['count'] > 0,
				'panelOpen'      => true,
				'sidebarShown'   => true,
				'isBasketStep'   => true,
				'isFinalizeStep' => false,
				'isDoneStep'     => false,
				'saveText'       => '',
				'busy'           => false,
				'canRetry'       => false,
				'doneText'       => '',
				'hasAidPending'  => OPENBB_Order::AID_PENDING === $order_state['aid_status'],
			)
		);
	}

	/**
	 * Strings for the store. Placeholders follow sprintf (%s, %d, %1$s).
	 */
	private static function messages(): array {
		return array(
			'itemsOne'      => _n( '%d item', '%d items', 1, 'open-bulk-buying' ),
			'itemsOther'    => _n( '%d item', '%d items', 2, 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'added'         => __( '%s added to your basket.', 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'removed'       => __( '%s removed from your basket.', 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'qtyLabel'      => __( 'Quantity, %s', 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'incLabel'      => __( 'Add one %s', 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'decLabel'      => __( 'Remove one %s', 'open-bulk-buying' ),
			/* translators: %s: product name. */
			'removeLabel'   => __( 'Remove %s from your basket', 'open-bulk-buying' ),
			'soldOut'       => __( 'Sold out', 'open-bulk-buying' ),
			/* translators: %d: number of units available. */
			'lowStock'      => __( 'Only %d available', 'open-bulk-buying' ),
			/* translators: 1: price, 2: selling unit, e.g. "500 g". */
			'priceLabel'    => __( '%1$s / %2$s', 'open-bulk-buying' ),
			/* translators: 1: total amount saved, 2: recommended number of containers. */
			'containerTip'  => __( 'Save up to %1$s by bringing your own containers. We recommend bringing about %2$d.', 'open-bulk-buying' ),
			/* translators: %d: number of units available. */
			'maxReached'    => __( 'Only %d available.', 'open-bulk-buying' ),
			'saving'        => __( 'Saving…', 'open-bulk-buying' ),
			'saved'         => __( 'Basket saved', 'open-bulk-buying' ),
			'savedOrder'    => __( 'Changes saved to your order', 'open-bulk-buying' ),
			'saveFailed'    => __( 'Not saved', 'open-bulk-buying' ),
			'network'       => __( 'Something went wrong. Check your connection and try again.', 'open-bulk-buying' ),
			'fixErrors'     => __( 'Some details need attention.', 'open-bulk-buying' ),
			'donation'      => __( 'Enter an amount, like 5 or 5.50.', 'open-bulk-buying' ),
			'aidReason'     => __( 'Tell us briefly why you are asking for financial aid.', 'open-bulk-buying' ),
			'emptyBasket'   => __( 'Add at least one item before submitting your order.', 'open-bulk-buying' ),
			'doneSubmitted' => __( 'Your order is placed. You can keep changing it until ordering closes.', 'open-bulk-buying' ),
			'doneUpdated'   => __( 'Your order details are saved.', 'open-bulk-buying' ),
		);
	}

	/**
	 * Catalog sections as the templates need them.
	 *
	 * @param array $sections From OPENBB_Catalog::sections().
	 */
	private static function sections( array $sections ): array {
		$rows = array();
		foreach ( $sections as $index => $section ) {
			$level    = $section['depth'] > 0 ? 4 : 3;
			$anchor   = self::ID . '-section-' . ( $section['id'] ? $section['id'] : 'other' );
			$products = array();
			foreach ( $section['products'] as $product ) {
				$products[] = self::product_row( $product, $anchor, $level + 1 );
			}
			$rows[] = array(
				'name'     => $section['name'],
				'anchor'   => $anchor,
				'level'    => $level,
				'depth'    => $section['depth'],
				'index'    => $index,
				'products' => $products,
			);
		}

		return $rows;
	}

	private static function product_row( OPENBB_Product $product, string $section_anchor, int $level ): array {
		$supplier = $product->supplier_id ? get_term( $product->supplier_id, OPENBB_SUPPLIER_TAXONOMY ) : null;
		$meta     = array_filter(
			array(
				$product->brand,
				$supplier instanceof WP_Term ? openbb_term_name( $supplier ) : '',
			),
			'strlen'
		);
		$image    = $product->image_id ? wp_get_attachment_image(
			$product->image_id,
			'thumbnail',
			false,
			array(
				'class'    => 'openbb-product__image',
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		) : '';

		return array(
			'id'          => $product->id,
			'uid'         => $section_anchor . '-p' . $product->id,
			'level'       => min( 6, $level ),
			'name'        => $product->get_name(),
			'meta'        => implode( ' · ', array_unique( $meta ) ),
			'image'       => $image,
			'price'       => openbb_format_money( $product->price + $product->container_charge ),
			'unit'        => $product->get_unit_label(),
			'charge'      => $product->container_charge > 0
				? sprintf(
					/* translators: %s: container charge. */
					__( 'Includes a %s container charge, taken off if you bring your own.', 'open-bulk-buying' ),
					openbb_format_money( $product->container_charge )
				)
				: '',
			'description' => wp_kses_post( $product->get_description() ),
		);
	}

	/**
	 * The fields of the Finalize step, for openbb_render_field().
	 */
	private static function finalize_fields( array $order_state, bool $submitted, int $digits, string $pickup ): array {
		$prefix = self::ID;
		$fields = array(
			'donation'    => array(
				'name'      => 'donation',
				'label'     => __( 'Donation to the co-op (optional)', 'open-bulk-buying' ),
				'help'      => __( 'Added to your total. Donations help keep prices low for everyone.', 'open-bulk-buying' ),
				'kind'      => 'text',
				'inputmode' => $digits > 0 ? 'decimal' : 'numeric',
				'maxlength' => 12,
				'value'     => self::money_input( $order_state['donation'], $digits ),
				'id_prefix' => $prefix,
			),
			'aid'         => array(
				'name'      => 'aid',
				'label'     => __( 'I would like to request financial aid for this order', 'open-bulk-buying' ),
				'help'      => __( 'Requests are confidential. The amount depends on the donations available and is taken off your total if approved. We may ask you to verify your financial situation.', 'open-bulk-buying' ),
				'kind'      => 'checkbox',
				'value'     => OPENBB_Order::AID_NONE !== $order_state['aid_status'],
				'id_prefix' => $prefix,
			),
			'aid_reason'  => array(
				'name'      => 'aid_reason',
				'label'     => __( 'Why are you asking for aid?', 'open-bulk-buying' ),
				'kind'      => 'textarea',
				'maxlength' => OPENBB_Member_Orders::MAX_TEXT,
				'value'     => $order_state['aid_reason'],
				'id_prefix' => $prefix,
			),
			'member_note' => array(
				'name'      => 'member_note',
				'label'     => __( 'Note for the co-op (optional)', 'open-bulk-buying' ),
				'kind'      => 'textarea',
				'maxlength' => OPENBB_Member_Orders::MAX_TEXT,
				'value'     => $order_state['member_note'],
				'id_prefix' => $prefix,
			),
		);
		if ( ! $submitted ) {
			$fields['commit'] = array(
				'name'      => 'commit',
				'label'     => $pickup,
				'kind'      => 'checkbox',
				'required'  => true,
				'value'     => false,
				'id_prefix' => $prefix,
			);
		}

		return $fields;
	}

	/**
	 * Extra questions for the Finalize step, from the openbb_order_form_questions action.
	 */
	private static function questions( OPENBB_Cycle $cycle, ?OPENBB_Order $order ): string {
		ob_start();
		/**
		 * Prints extra questions (e.g. polls) into the order form's Finalize step.
		 *
		 * @param OPENBB_Cycle      $cycle The cycle being ordered from.
		 * @param OPENBB_Order|null $order The member's order, if saved.
		 */
		do_action( 'openbb_order_form_questions', $cycle, $order );

		return (string) ob_get_clean();
	}

	/**
	 * What the member commits to, naming the pickup place and time when set.
	 */
	private static function pickup_text( OPENBB_Cycle $cycle ): string {
		$when = '';
		if ( $cycle->pickup_opens_at && $cycle->pickup_closes_at ) {
			$opens = strtotime( $cycle->pickup_opens_at . ' UTC' );
			$close = strtotime( $cycle->pickup_closes_at . ' UTC' );
			$when  = wp_date( 'Y-m-d', $opens ) === wp_date( 'Y-m-d', $close )
				/* translators: 1: date, 2: start time, 3: end time. */
				? sprintf( __( '%1$s, %2$s–%3$s', 'open-bulk-buying' ), wp_date( get_option( 'date_format' ), $opens ), wp_date( get_option( 'time_format' ), $opens ), wp_date( get_option( 'time_format' ), $close ) )
				/* translators: 1: start date and time, 2: end date and time. */
				: sprintf( __( '%1$s to %2$s', 'open-bulk-buying' ), self::local_datetime( $cycle->pickup_opens_at ), self::local_datetime( $cycle->pickup_closes_at ) );
		}
		$where = trim( $cycle->pickup_location );

		if ( '' !== $where && '' !== $when ) {
			/* translators: 1: pickup location, 2: pickup date and time. */
			return sprintf( __( 'I will pick up and pay for this order at %1$s (%2$s).', 'open-bulk-buying' ), $where, $when );
		}
		if ( '' !== $when ) {
			/* translators: %s: pickup date and time. */
			return sprintf( __( 'I will pick up and pay for this order on %s.', 'open-bulk-buying' ), $when );
		}

		return __( 'I will pick up and pay for this order on distribution day.', 'open-bulk-buying' );
	}

	/**
	 * A notice above the catalog about the order's state, or null.
	 *
	 * @return array{type: string, text: string}|null
	 */
	private static function status_notice( ?OPENBB_Order $order, array $order_state, OPENBB_Cycle $cycle ): ?array {
		if ( ! $order ) {
			return null;
		}
		if ( ! $order_state['editable'] ) {
			return array(
				'type' => 'info',
				'text' => OPENBB_Order::STATUS_CANCELLED === $order->status
					? __( 'This order was cancelled. Contact the co-op if you have questions.', 'open-bulk-buying' )
					: __( 'Your order is confirmed and can no longer be changed here. Contact the co-op if something needs fixing.', 'open-bulk-buying' ),
			);
		}
		if ( OPENBB_Order::STATUS_SUBMITTED === $order->status ) {
			return array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: %s: date and time. */
					__( 'Your order is in. You can change it until %s; changes to your basket save automatically.', 'open-bulk-buying' ),
					self::local_datetime( $cycle->closes_at )
				),
			);
		}

		return null;
	}

	/**
	 * Links to switch between cycles when several are open.
	 *
	 * @param OPENBB_Cycle[] $cycles
	 */
	private static function cycle_links( array $cycles, OPENBB_Cycle $current ): array {
		$links = array();
		foreach ( $cycles as $cycle ) {
			$links[] = array(
				'name'    => $cycle->name,
				'url'     => add_query_arg( 'cycle', $cycle->id, OPENBB_Frontend::current_url() ),
				'current' => $cycle->id === $current->id,
			);
		}

		return $links;
	}

	/**
	 * Login and signup for a visitor. The catalog stays out of the page (it isn't public),
	 * so both reload this page afterwards, landing on the form or the member's status.
	 *
	 * @param string $first login or signup.
	 */
	private static function auth( string $first ): string {
		$redirect = OPENBB_Frontend::current_url() . '#' . self::ID;
		$open     = OPENBB_Signup::is_open();

		$panels = array( 'login' => OPENBB_Login_View::panel( array( 'login_redirect' => $redirect ), $open ) );
		if ( $open ) {
			$panels['signup'] = OPENBB_Signup_View::panel( $redirect, true );
		}

		return self::message(
			__( 'Order', 'open-bulk-buying' ),
			$open
				? __( 'Log in or sign up to place your order.', 'open-bulk-buying' )
				: __( 'Log in to place your order.', 'open-bulk-buying' )
		) . OPENBB_Login_View::auth( $first, $panels );
	}

	private static function message( string $title, string $text ): string {
		return OPENBB_Frontend::template(
			'order-message',
			array(
				'id'    => self::ID,
				'title' => $title,
				'text'  => $text,
			)
		);
	}

	/**
	 * An amount in minor units as a plain decimal for an input, '' for zero.
	 */
	private static function money_input( int $minor, int $digits ): string {
		return $minor > 0 ? number_format( $minor / ( 10 ** $digits ), $digits, '.', '' ) : '';
	}

	private static function local_datetime( ?string $utc ): string {
		if ( ! $utc ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $utc . ' UTC' ) );
	}
}
