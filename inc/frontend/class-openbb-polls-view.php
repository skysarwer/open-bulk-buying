<?php
/**
 * Polls for members: [openbb_polls], and the poll surfaces of the order form.
 *
 * Active members answer the polls that are open to them, with or without an order. A
 * response belongs to the member: it's saved through the poll route, never as part of an
 * order, and they can change it until the poll closes. No question is required.
 *
 * Surfaces, all showing the same polls with the same answers:
 * - The polls view: every open poll as a form. With ?poll=ID, that poll alone. Rendered by
 *   the shortcode, and by the order form for ?poll=ID so a poll can be answered from the
 *   order page whether or not ordering is open. Once saved, a poll's form gives way to a
 *   confirmation leading back to the answers and, from the order form, to the order page.
 * - Cards on the order page linking to a poll (placement "card").
 * - Independent poll forms on the order thank-you page (placement "thank_you"), which
 *   confirm a save under the form.
 *
 * Templates: polls (the view), poll (one poll's form), poll-cards.
 * Store: assets/js/polls.js (`openbb/polls`). Styles: src/sass/frontend/_polls.scss.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Polls_View {

	const ID = 'openbb-polls';

	/** Query argument naming one poll to show. */
	const QUERY_ARG = 'poll';

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
		if ( ! $member || ! $member->is_active() || ! current_user_can( 'openbb_answer_polls' ) ) {
			return self::message(
				$member && $member->is_applicant()
					? __( 'Your membership application is being reviewed. You can answer polls once it is approved.', 'open-bulk-buying' )
					: __( 'Only active co-op members can answer polls.', 'open-bulk-buying' )
			);
		}

		return self::polls( $user_id, self::requested() );
	}

	/**
	 * The poll id asked for in the URL, or 0.
	 */
	public static function requested(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of which poll to show.
		return isset( $_GET[ self::QUERY_ARG ] ) ? absint( $_GET[ self::QUERY_ARG ] ) : 0;
	}

	/**
	 * The polls view for an active member: every poll open to them, or only $poll_id.
	 * When that poll can't be answered, says why and lists the others.
	 *
	 * @param array $back Optional link out of the view: url, label (e.g. back to the order form).
	 *                    Each poll's confirmation also offers it as the main action.
	 */
	public static function polls( int $user_id, int $poll_id = 0, array $back = array() ): string {
		OPENBB_Frontend::no_cache();

		$open   = OPENBB_Polls::open_for( $user_id );
		$notice = '';
		if ( $poll_id ) {
			$single = array_values(
				array_filter(
					$open,
					static function ( array $entry ) use ( $poll_id ): bool {
						return $entry['poll']->id === $poll_id;
					}
				)
			);
			if ( $single ) {
				$open = $single;
			} else {
				$poll   = OPENBB_Polls::get( $poll_id );
				$access = $poll ? OPENBB_Polls::check_access( $poll, $user_id ) : null;
				$notice = is_wp_error( $access ) ? $access->get_error_message() : __( 'Poll not found.', 'open-bulk-buying' );
			}
		}

		OPENBB_Frontend::enqueue_style();

		return OPENBB_Frontend::process(
			OPENBB_Frontend::template(
				'polls',
				array(
					'id'     => self::ID,
					'notice' => $notice,
					'back'   => $back,
					'polls'  => self::forms( $open, $user_id, self::ID, true, $back ),
					'empty'  => $poll_id && $notice
						? __( 'There are no other polls to answer right now.', 'open-bulk-buying' )
						: __( 'There are no polls to answer right now.', 'open-bulk-buying' ),
				)
			)
		);
	}

	/**
	 * The same answer forms and poll store serve the polls page and the order confirmation.
	 *
	 * @param bool  $confirmation Whether saving swaps a form for a confirmation, rather than
	 *                            confirming under the form.
	 * @param array $back         The confirmation's main action: url, label, or empty.
	 */
	private static function forms( array $open, int $user_id, string $prefix, bool $confirmation, array $back = array() ): array {
		if ( ! $open ) {
			return array();
		}
		OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_POLLS ) );
		OPENBB_Frontend::forms_state();
		self::state();

		$forms = array();
		foreach ( $open as $entry ) {
			$forms[] = self::poll_view( $entry['poll'], $entry['cycle'], $user_id, $prefix . '-' . $entry['poll']->id, $confirmation, $back );
		}
		return $forms;
	}

	private static function poll_view( OPENBB_Poll $poll, OPENBB_Cycle $cycle, int $user_id, string $id, bool $confirmation, array $back ): array {
		$answers = OPENBB_Poll_Responses::get_answers( $poll, $user_id );
		$fields  = array();
		$values  = array();
		foreach ( $poll->active_questions() as $key => $question ) {
			$field                    = openbb_poll_field_input( $question, $answers[ $key ] ?? null, array( 'id_prefix' => $id ) );
			$fields[]                 = $field;
			$values[ $field['name'] ] = $field['value'];
		}

		return array(
			'id'           => $id,
			'title'        => $poll->get_title(),
			'intro'        => $poll->get_intro(),
			'cycle_name'   => $cycle->name,
			'fields'       => $fields,
			'confirmation' => $confirmation,
			'back'         => $back,
			'context'      => array(
				'pollId'       => $poll->id,
				'responded'    => (bool) $answers,
				'busy'         => false,
				'notice'       => '',
				'status'       => '',
				'confirmation' => $confirmation,
				'confirmed'    => false,
			),
			'form_context' => array(
				'values' => $values,
				'errors' => new stdClass(),
			),
		);
	}

	/**
	 * Client state for `openbb/polls`.
	 */
	private static function state(): void {
		wp_interactivity_state(
			'openbb/polls',
			array(
				'restUrl'  => rest_url( OPENBB_REST_Controller::API_NAMESPACE . '/poll' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'messages' => array(
					'saved'     => __( 'Your answers are saved. You can change them until the poll closes.', 'open-bulk-buying' ),
					'cleared'   => __( 'Your answers were removed.', 'open-bulk-buying' ),
					'empty'     => __( 'Answer at least one question.', 'open-bulk-buying' ),
					'fixErrors' => __( 'Some answers need attention.', 'open-bulk-buying' ),
					'network'   => __( 'Something went wrong. Check your connection and try again.', 'open-bulk-buying' ),
				),
			)
		);
	}

	/**
	 * Cards for the order page: an invitation to each open poll the member hasn't
	 * answered, and a quieter link to change the ones they have. '' when there are none.
	 */
	public static function cards( int $user_id ): string {
		$open = self::placed( $user_id, OPENBB_Poll::PLACEMENT_CARD );
		if ( ! $open ) {
			return '';
		}

		$responded = OPENBB_Poll_Responses::responded( $user_id, wp_list_pluck( wp_list_pluck( $open, 'poll' ), 'id' ) );
		$cards     = array();
		$answered  = array();
		foreach ( $open as $entry ) {
			$poll = $entry['poll'];
			$item = array(
				'title' => $poll->get_title(),
				'intro' => wp_trim_words( $poll->get_intro(), 30 ),
				'url'   => self::url( $poll->id ),
			);
			if ( in_array( $poll->id, $responded, true ) ) {
				$answered[] = $item;
			} else {
				$cards[] = $item;
			}
		}

		return OPENBB_Frontend::template(
			'poll-cards',
			array(
				'cards'    => $cards,
				'answered' => $answered,
			)
		);
	}

	/**
	 * Evaluate eligibility after the order is saved: placing it can qualify the member.
	 */
	public static function thank_you_polls( int $user_id ): array {
		return self::forms( self::placed( $user_id, OPENBB_Poll::PLACEMENT_THANK_YOU ), $user_id, 'openbb-thank-you-poll', false );
	}

	private static function placed( int $user_id, string $placement ): array {
		return array_filter(
			OPENBB_Polls::open_for( $user_id ),
			static function ( array $entry ) use ( $placement ): bool {
				return $entry['poll']->has_placement( $placement );
			}
		);
	}

	/**
	 * Where a member answers $poll_id: the co-op's polls page when one is set (see
	 * page_url()), else the page being shown, which the polls and order views both handle.
	 */
	public static function url( int $poll_id ): string {
		$page = self::page_url( $poll_id );

		return '' !== $page ? $page : add_query_arg( self::QUERY_ARG, $poll_id, OPENBB_Frontend::current_url() ) . '#' . self::ID;
	}

	/**
	 * The link to $poll_id on the co-op's polls page, or '' when the site hasn't named one.
	 * The plugin doesn't know which page holds [openbb_polls] or the order form, so a link
	 * that works anywhere (emails, the admin) needs the 'openbb_polls_page_url' filter.
	 */
	public static function page_url( int $poll_id ): string {
		/**
		 * Filters the address of the page members answer polls on: one with the
		 * [openbb_polls] or [openbb_order_form] shortcode. Empty by default.
		 *
		 * @param string $url     Page URL.
		 * @param int    $poll_id The poll being linked to.
		 */
		$page = apply_filters( 'openbb_polls_page_url', '', $poll_id );
		$page = is_string( $page ) ? esc_url_raw( $page ) : '';

		return '' !== $page ? add_query_arg( self::QUERY_ARG, $poll_id, $page ) . '#' . self::ID : '';
	}

	/**
	 * Text shown with every poll form: answers are not anonymous.
	 */
	public static function privacy_note(): string {
		return __( 'Co-op staff can see your answers.', 'open-bulk-buying' );
	}

	/**
	 * Login and signup for a visitor; both reload this page afterwards.
	 *
	 * @param string $first login or signup.
	 */
	private static function auth( string $first ): string {
		$redirect = add_query_arg( self::QUERY_ARG, self::requested() ? self::requested() : false, OPENBB_Frontend::current_url() ) . '#' . self::ID;
		$open     = OPENBB_Signup::is_open();

		$panels = array( 'login' => OPENBB_Login_View::panel( array( 'login_redirect' => $redirect ), $open ) );
		if ( $open ) {
			$panels['signup'] = OPENBB_Signup_View::panel( $redirect, true );
		}

		return self::message(
			$open
				? __( 'Log in or sign up to answer co-op polls.', 'open-bulk-buying' )
				: __( 'Log in to answer co-op polls.', 'open-bulk-buying' )
		) . OPENBB_Login_View::auth( $first, $panels );
	}

	private static function message( string $text ): string {
		return OPENBB_Frontend::template(
			'polls',
			array(
				'id'     => self::ID,
				'notice' => '',
				'back'   => array(),
				'polls'  => array(),
				'empty'  => $text,
			)
		);
	}
}
