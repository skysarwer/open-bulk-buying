<?php
/**
 * Polls: definitions, lifecycle, audience and who can answer. Datetimes are UTC.
 *
 * Staff edits carry the poll's revision; a save based on an outdated copy is refused
 * with a 409. Member answers are OPENBB_Poll_Responses.
 *
 * Poll questions use the shared field engine (OPENBB_Field_Schema) with their own rules:
 * a question is never required, and a single-choice question can be a star rating.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Polls {

	const TITLE_MAX     = 200;
	const INTRO_MAX     = 2000;
	const MAX_QUESTIONS = 50;

	/** Order statuses that count as having ordered in a cycle. Drafts and cancelled orders don't. */
	const ORDERED_STATUSES = array( OPENBB_Order::STATUS_SUBMITTED, OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_FULFILLED );

	public static function schema(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		return array(
			$wpdb->openbb_polls          => "CREATE TABLE {$wpdb->openbb_polls} (
				id bigint(20) unsigned NOT NULL auto_increment,
				cycle_id bigint(20) unsigned NOT NULL,
				title text NULL,
				intro longtext NULL,
				status varchar(20) NOT NULL default 'draft',
				open_rule varchar(20) NOT NULL default 'stage',
				open_stage varchar(20) NOT NULL default 'ordering',
				open_at datetime NULL default NULL,
				close_rule varchar(20) NOT NULL default 'stage',
				close_stage varchar(20) NOT NULL default 'processing',
				close_at datetime NULL default NULL,
				placements text NULL,
				audience varchar(30) NOT NULL default 'all',
				audience_cycles text NULL,
				questions longtext NULL,
				revision bigint(20) unsigned NOT NULL default 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint(20) unsigned NULL default NULL,
				published_at datetime NULL default NULL,
				closed_at datetime NULL default NULL,
				closed_by bigint(20) unsigned NULL default NULL,
				PRIMARY KEY  (id),
				KEY cycle_id (cycle_id),
				KEY status (status)
			) {$charset};",
			$wpdb->openbb_poll_responses => "CREATE TABLE {$wpdb->openbb_poll_responses} (
				id bigint(20) unsigned NOT NULL auto_increment,
				poll_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY poll_user (poll_id,user_id),
				KEY user_id (user_id)
			) {$charset};",
			$wpdb->openbb_poll_answers   => "CREATE TABLE {$wpdb->openbb_poll_answers} (
				id bigint(20) unsigned NOT NULL auto_increment,
				response_id bigint(20) unsigned NOT NULL,
				poll_id bigint(20) unsigned NOT NULL,
				question_key varchar(40) NOT NULL,
				value_key varchar(40) NULL default NULL,
				value_text longtext NULL,
				PRIMARY KEY  (id),
				KEY response_id (response_id),
				KEY tally (poll_id,question_key,value_key)
			) {$charset};",
		);
	}

	/**
	 * The rules poll questions give the shared field engine.
	 */
	public static function question_config(): array {
		return array(
			'error_code' => 'openbb_invalid_poll',
			'styles'     => array( OPENBB_Field_Schema::STYLE_SELECT, OPENBB_Field_Schema::STYLE_CHIPS, OPENBB_Field_Schema::STYLE_STARS ),
			'required'   => false,
		);
	}

	public static function normalize_question( array $input ): array {
		return OPENBB_Field_Schema::normalize_field( $input, self::question_config() );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function validate_question( array $question ) {
		return OPENBB_Field_Schema::validate_field( $question, self::question_config() );
	}

	public static function get( int $id ): ?OPENBB_Poll {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_polls} WHERE id = %d", $id ), ARRAY_A );

		return $row ? new OPENBB_Poll( $row ) : null;
	}

	/**
	 * A cycle's polls, oldest first.
	 *
	 * @return OPENBB_Poll[]
	 */
	public static function for_cycle( int $cycle_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_polls} WHERE cycle_id = %d ORDER BY id", $cycle_id ), ARRAY_A );

		return array_map(
			static function ( array $row ): OPENBB_Poll {
				return new OPENBB_Poll( $row );
			},
			(array) $rows
		);
	}

	/**
	 * Creates a poll (null $existing; $input needs cycle_id) or changes one.
	 *
	 * $input keys, all optional when editing: title and intro (localized maps), open_rule,
	 * open_stage, open_at, close_rule, close_stage, close_at (UTC datetimes), placements,
	 * audience, audience_cycles, and questions (the whole list, in order).
	 *
	 * Once a poll has been published, its questions keep their key and type and can only
	 * be archived, never removed, so stored answers keep their meaning.
	 *
	 * @param int $base_revision The revision the editor loaded; ignored for a new poll.
	 * @return OPENBB_Poll|WP_Error
	 */
	public static function save( ?OPENBB_Poll $existing, array $input, int $base_revision, int $actor_id ) {
		if ( $existing && OPENBB_Poll::STATUS_CLOSED === $existing->status ) {
			return new WP_Error( 'openbb_poll_closed', __( 'A closed poll can\'t be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( $existing && $existing->revision !== $base_revision ) {
			return self::stale();
		}

		$poll = $existing ? clone $existing : new OPENBB_Poll();
		if ( ! $existing ) {
			$cycle = OPENBB_Cycles::get( (int) ( $input['cycle_id'] ?? 0 ) );
			if ( ! $cycle ) {
				return new WP_Error( 'openbb_cycle_not_found', __( 'Cycle not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			$poll->cycle_id   = $cycle->id;
			$poll->created_by = $actor_id;
		}

		foreach ( array( 'title', 'intro' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$poll->$field = 'title' === $field
					? openbb_sanitize_localized_text( $input[ $field ] )
					: array_map( 'sanitize_textarea_field', openbb_normalize_localized( $input[ $field ] ) );
			}
		}
		foreach ( array( 'open_rule', 'open_stage', 'close_rule', 'close_stage', 'audience' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$poll->$field = is_string( $input[ $field ] ) ? $input[ $field ] : '';
			}
		}
		foreach ( array( 'open_at', 'close_at' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$poll->$field = is_string( $input[ $field ] ) && '' !== $input[ $field ] ? $input[ $field ] : null;
			}
		}
		if ( array_key_exists( 'placements', $input ) ) {
			$poll->placements = array_values( array_intersect( OPENBB_Poll::PLACEMENTS, array_filter( (array) $input['placements'], 'is_string' ) ) );
		}
		if ( array_key_exists( 'audience_cycles', $input ) ) {
			$poll->audience_cycles = array_values( array_unique( array_filter( array_map( 'absint', array_filter( (array) $input['audience_cycles'], 'is_scalar' ) ) ) ) );
		}
		if ( array_key_exists( 'questions', $input ) ) {
			$questions = array();
			foreach ( (array) $input['questions'] as $question ) {
				if ( ! is_array( $question ) ) {
					return self::invalid( __( 'Each question must be an object.', 'open-bulk-buying' ) );
				}
				$questions[] = self::normalize_question( $question );
			}
			$poll->questions = $questions;
		}

		$valid = self::validate( $poll, $existing );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::persist( $poll, $existing ? $base_revision : null );
	}

	/**
	 * Checks a poll's own settings and, against the saved poll, that its questions keep
	 * their meaning. Unused rule fields are reset so they can't hold stale values.
	 *
	 * @return true|WP_Error
	 */
	private static function validate( OPENBB_Poll $poll, ?OPENBB_Poll $old ) {
		if ( '' === trim( $poll->title['default'] ) ) {
			return self::invalid( __( 'Give the poll a title.', 'open-bulk-buying' ) );
		}
		foreach ( array( 'title' => self::TITLE_MAX, 'intro' => self::INTRO_MAX ) as $field => $max ) {
			foreach ( $poll->$field as $text ) {
				if ( mb_strlen( $text ) > $max ) {
					return self::invalid(
						'title' === $field
							/* translators: %d: maximum number of characters. */
							? sprintf( __( 'The title can be at most %d characters.', 'open-bulk-buying' ), $max )
							/* translators: %d: maximum number of characters. */
							: sprintf( __( 'The introduction can be at most %d characters.', 'open-bulk-buying' ), $max )
					);
				}
			}
		}

		if ( ! in_array( $poll->open_rule, OPENBB_Poll::OPEN_RULES, true ) || ! in_array( $poll->close_rule, OPENBB_Poll::CLOSE_RULES, true ) ) {
			return self::invalid( __( 'Choose when the poll opens and closes.', 'open-bulk-buying' ) );
		}
		$stages = OPENBB_Cycle::STATUSES;
		foreach ( array( 'open', 'close' ) as $side ) {
			$rule  = $side . '_rule';
			$stage = $side . '_stage';
			$date  = $side . '_at';
			if ( OPENBB_Poll::RULE_STAGE !== $poll->$rule ) {
				$poll->$stage = 'open' === $side ? OPENBB_Cycle::STATUS_ORDERING : OPENBB_Cycle::STATUS_PROCESSING;
			} elseif ( ! in_array( $poll->$stage, $stages, true ) ) {
				return self::invalid( __( 'Choose a cycle stage.', 'open-bulk-buying' ) );
			}
			if ( OPENBB_Poll::RULE_DATE !== $poll->$rule ) {
				$poll->$date = null;
			} elseif ( ! $poll->$date || false === strtotime( $poll->$date . ' UTC' ) ) {
				return self::invalid(
					'open' === $side
						? __( 'Set the date and time the poll opens.', 'open-bulk-buying' )
						: __( 'Set the date and time the poll closes.', 'open-bulk-buying' )
				);
			}
		}
		if ( OPENBB_Poll::RULE_STAGE === $poll->close_rule ) {
			// Every cycle has reached its first stage, so the poll would never be open.
			$first = OPENBB_Poll::RULE_STAGE === $poll->open_rule ? array_search( $poll->open_stage, $stages, true ) : 0;
			if ( array_search( $poll->close_stage, $stages, true ) <= $first ) {
				return self::invalid( __( 'The poll must close at a later cycle stage than the one it opens at.', 'open-bulk-buying' ) );
			}
		}
		if ( OPENBB_Poll::RULE_DATE === $poll->open_rule && OPENBB_Poll::RULE_DATE === $poll->close_rule
			&& strtotime( $poll->close_at . ' UTC' ) <= strtotime( $poll->open_at . ' UTC' ) ) {
			return self::invalid( __( 'The poll must close after it opens.', 'open-bulk-buying' ) );
		}

		if ( ! in_array( $poll->audience, OPENBB_Poll::AUDIENCES, true ) ) {
			return self::invalid( __( 'Choose who can answer the poll.', 'open-bulk-buying' ) );
		}
		if ( OPENBB_Poll::AUDIENCE_ORDERED !== $poll->audience ) {
			$poll->audience_cycles = array();
		}

		if ( count( $poll->questions ) > self::MAX_QUESTIONS ) {
			return self::invalid(
				/* translators: %d: maximum number of questions. */
				sprintf( __( 'A poll can have at most %d questions.', 'open-bulk-buying' ), self::MAX_QUESTIONS )
			);
		}
		$answerable = $old && OPENBB_Poll::STATUS_DRAFT !== $old->status;
		$valid      = OPENBB_Field_Schema::validate_fields( $poll->questions, $answerable ? $old->questions : array(), array(), self::question_config() );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( $answerable ) {
			$saved = $old->questions_by_key();
			foreach ( $poll->questions as $question ) {
				$before = $saved[ $question['key'] ] ?? null;
				if ( $before && OPENBB_Field_Schema::TYPE_OPTIONS === $question['type'] && $before['options']['multiple'] !== $question['options']['multiple'] ) {
					return OPENBB_Field_Schema::error(
						/* translators: %s: question label. */
						sprintf( __( 'Whether "%s" allows several choices can\'t change once the poll is published.', 'open-bulk-buying' ), $question['label']['default'] ),
						$question['key'],
						self::question_config()
					);
				}
			}

			return self::publishable( $poll );
		}

		return true;
	}

	/**
	 * What a poll needs before members can be asked to answer it.
	 *
	 * @return true|WP_Error
	 */
	private static function publishable( OPENBB_Poll $poll ) {
		if ( ! $poll->active_questions() ) {
			return self::invalid( __( 'Add at least one question before publishing the poll.', 'open-bulk-buying' ), 'openbb_poll_incomplete' );
		}
		if ( OPENBB_Poll::AUDIENCE_ORDERED === $poll->audience && ! $poll->audience_cycles ) {
			return self::invalid( __( 'Choose at least one cycle whose members can answer the poll.', 'open-bulk-buying' ), 'openbb_poll_incomplete' );
		}

		return true;
	}

	/**
	 * Publishes a draft or closes a published poll, on behalf of $actor_id. Closing is
	 * final: answers stay, and the poll can't be reopened.
	 *
	 * @return OPENBB_Poll|WP_Error
	 */
	public static function set_status( OPENBB_Poll $poll, string $status, int $base_revision, int $actor_id ) {
		if ( $poll->revision !== $base_revision ) {
			return self::stale();
		}

		$poll = clone $poll;
		$old  = $poll->status;
		$now  = current_time( 'mysql', true );
		if ( OPENBB_Poll::STATUS_PUBLISHED === $status && OPENBB_Poll::STATUS_DRAFT === $old ) {
			$valid = self::publishable( $poll );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$poll->published_at = $now;
		} elseif ( OPENBB_Poll::STATUS_CLOSED === $status && OPENBB_Poll::STATUS_PUBLISHED === $old ) {
			$poll->closed_at = $now;
			$poll->closed_by = $actor_id;
		} else {
			return new WP_Error( 'openbb_poll_status', __( 'A draft can be published and a published poll can be closed. Nothing else can change a poll\'s status.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		$poll->status = $status;

		$saved = self::persist( $poll, $base_revision );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		/**
		 * Fires after a poll is published or closed.
		 *
		 * @param OPENBB_Poll $poll       The saved poll.
		 * @param string      $old_status Previous status.
		 * @param int         $actor_id   User who made the change.
		 */
		do_action( 'openbb_poll_status_changed', $saved, $old, $actor_id );

		return $saved;
	}

	/**
	 * Writes the poll. An existing poll's revision is claimed first, in one statement, so
	 * two saves from the same revision can't both go through.
	 *
	 * @param int|null $base_revision Null for a new poll.
	 * @return OPENBB_Poll|WP_Error
	 */
	private static function persist( OPENBB_Poll $poll, ?int $base_revision ) {
		global $wpdb;

		if ( null === $base_revision ) {
			$poll->revision = 1;
		} else {
			$claimed = $wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->openbb_polls} SET revision = revision + 1 WHERE id = %d AND revision = %d", $poll->id, $base_revision )
			);
			if ( 1 !== $claimed ) {
				return self::stale();
			}
			$poll->revision = $base_revision + 1;
		}

		if ( ! $poll->save() ) {
			return new WP_Error( 'openbb_poll_save_failed', __( 'The poll could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		return $poll;
	}

	/**
	 * Deletes a poll with every response to it.
	 *
	 * Not for use inside another transaction: MySQL commits the outer one on START TRANSACTION.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( OPENBB_Poll $poll ) {
		global $wpdb;

		$failed = new WP_Error( 'openbb_poll_delete_failed', __( 'The poll could not be deleted.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $failed;
		}
		$deleted = false !== $wpdb->delete( $wpdb->openbb_poll_answers, array( 'poll_id' => $poll->id ), array( '%d' ) )
			&& false !== $wpdb->delete( $wpdb->openbb_poll_responses, array( 'poll_id' => $poll->id ), array( '%d' ) )
			&& 1 === $wpdb->delete( $wpdb->openbb_polls, array( 'id' => $poll->id ), array( '%d' ) );
		if ( ! $deleted || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $failed;
		}

		return true;
	}

	/**
	 * Deletes a cycle's polls and their responses. Opens no transaction, so a cycle
	 * being deleted can include it in its own.
	 */
	public static function delete_for_cycle( int $cycle_id ): bool {
		global $wpdb;

		$polls = $wpdb->prepare( "SELECT id FROM {$wpdb->openbb_polls} WHERE cycle_id = %d", $cycle_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $polls is prepared above.
		return false !== $wpdb->query( "DELETE FROM {$wpdb->openbb_poll_answers} WHERE poll_id IN ({$polls})" )
			&& false !== $wpdb->query( "DELETE FROM {$wpdb->openbb_poll_responses} WHERE poll_id IN ({$polls})" )
			&& false !== $wpdb->delete( $wpdb->openbb_polls, array( 'cycle_id' => $cycle_id ), array( '%d' ) );
		// phpcs:enable
	}

	/**
	 * Copies a poll's definition into $target as a new draft. The copy shares nothing with
	 * its source: no answers, and later edits to either don't reach the other.
	 *
	 * What doesn't fit a different cycle is reset: archived questions and options are left
	 * out, dates fall back to the default stage rules, and an audience of people who
	 * ordered in chosen cycles is suggested again for the target cycle, for staff to review.
	 *
	 * @return OPENBB_Poll|WP_Error
	 */
	public static function copy( OPENBB_Poll $source, OPENBB_Cycle $target, int $actor_id ) {
		$questions = array();
		foreach ( $source->active_questions() as $question ) {
			if ( OPENBB_Field_Schema::TYPE_OPTIONS === $question['type'] ) {
				foreach ( $question['options']['groups'] as $index => $group ) {
					$question['options']['groups'][ $index ]['options'] = array_values(
						array_filter(
							$group['options'],
							static function ( array $option ): bool {
								return ! $option['archived'];
							}
						)
					);
				}
				$question['options']['groups'] = array_values(
					array_filter(
						$question['options']['groups'],
						static function ( array $group ): bool {
							return (bool) $group['options'];
						}
					)
				);
				if ( ! $question['options']['groups'] ) {
					continue;
				}
			}
			$questions[] = $question;
		}

		$input = array(
			'cycle_id'   => $target->id,
			'title'      => $source->title,
			'intro'      => $source->intro,
			'placements' => $source->placements,
			'audience'   => $source->audience,
			'questions'  => $questions,
		);
		foreach ( array( 'open', 'close' ) as $side ) {
			if ( OPENBB_Poll::RULE_DATE !== $source->{$side . '_rule'} ) {
				$input[ $side . '_rule' ]  = $source->{$side . '_rule'};
				$input[ $side . '_stage' ] = $source->{$side . '_stage'};
			}
		}
		if ( OPENBB_Poll::AUDIENCE_ORDERED === $source->audience ) {
			$suggested                = self::suggested_cycle( $target );
			$input['audience_cycles'] = $suggested ? array( $suggested ) : array();
		}

		$copy = self::save( null, $input, 0, $actor_id );
		// A date rule dropped on one side can leave stages the other way round.
		if ( is_wp_error( $copy ) && 'openbb_invalid_poll' === $copy->get_error_code() ) {
			unset( $input['open_rule'], $input['open_stage'], $input['close_rule'], $input['close_stage'] );
			$copy = self::save( null, $input, 0, $actor_id );
		}

		return $copy;
	}

	/**
	 * The cycle to suggest when a poll is limited to people who ordered: the most recent
	 * other cycle that opened before this one (before now, if this one has no dates yet)
	 * and has at least one committed order. Only a suggestion for the editor; who can
	 * answer always comes from the cycles saved on the poll.
	 *
	 * @return int Cycle id, or 0.
	 */
	public static function suggested_cycle( OPENBB_Cycle $cycle ): int {
		global $wpdb;

		$statuses = self::ORDERED_STATUSES;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.id FROM {$wpdb->openbb_cycles} c
				WHERE c.id != %d AND c.opens_at IS NOT NULL AND c.opens_at < %s
					AND EXISTS (
						SELECT 1 FROM {$wpdb->openbb_orders} o
						WHERE o.cycle_id = c.id AND o.status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
					)
				ORDER BY c.opens_at DESC, c.id DESC LIMIT 1',
				...array_merge( array( $cycle->id, $cycle->opens_at ? $cycle->opens_at : current_time( 'mysql', true ) ), $statuses )
			)
		);
	}

	/**
	 * Every cycle with the number of members who have a committed order in it, most
	 * recent first, for choosing a poll's audience.
	 *
	 * @return array[] id, name, status, opens_at, members.
	 */
	public static function audience_cycles(): array {
		global $wpdb;

		$statuses = self::ORDERED_STATUSES;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.name, c.status, c.opens_at, COUNT(DISTINCT o.user_id) AS members
				FROM {$wpdb->openbb_cycles} c
				LEFT JOIN {$wpdb->openbb_orders} o ON o.cycle_id = c.id AND o.status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
				GROUP BY c.id, c.name, c.status, c.opens_at
				ORDER BY c.opens_at IS NULL DESC, c.opens_at DESC, c.id DESC
				LIMIT 200',
				...$statuses
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'       => (int) $row['id'],
					'name'     => (string) $row['name'],
					'status'   => (string) $row['status'],
					'opens_at' => $row['opens_at'],
					'members'  => (int) $row['members'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Whether the poll's audience includes this member.
	 */
	public static function in_audience( OPENBB_Poll $poll, int $user_id ): bool {
		global $wpdb;

		if ( OPENBB_Poll::AUDIENCE_ORDERED !== $poll->audience ) {
			return true;
		}
		if ( ! $poll->audience_cycles ) {
			return false;
		}

		$statuses = self::ORDERED_STATUSES;

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->openbb_orders}
				WHERE user_id = %d
					AND cycle_id IN (" . implode( ',', array_fill( 0, count( $poll->audience_cycles ), '%d' ) ) . ')
					AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
				LIMIT 1',
				...array_merge( array( $user_id ), $poll->audience_cycles, $statuses )
			)
		);
	}

	/**
	 * Whether $user_id may answer $poll now. Checked on every read and write of a
	 * member's answers; what a page shows or hides is never the authorization.
	 *
	 * @return true|WP_Error
	 */
	public static function check_access( OPENBB_Poll $poll, int $user_id, ?OPENBB_Cycle $cycle = null, ?int $timestamp = null ) {
		$member = $user_id ? OPENBB_Member::get( $user_id ) : null;
		if ( ! $member || ! $member->is_active() || ! user_can( $user_id, 'openbb_answer_polls' ) ) {
			return new WP_Error( 'openbb_not_member', __( 'Only active co-op members can answer polls.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		$cycle = $cycle ?? OPENBB_Cycles::get( $poll->cycle_id );
		// A draft isn't announced, so it reads as missing rather than as closed.
		if ( ! $cycle || OPENBB_Poll::STATUS_DRAFT === $poll->status ) {
			return new WP_Error( 'openbb_poll_not_found', __( 'Poll not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
		}
		switch ( $poll->phase( $cycle, $timestamp ) ) {
			case OPENBB_Poll::PHASE_OPEN:
				break;
			case OPENBB_Poll::PHASE_SCHEDULED:
				return new WP_Error( 'openbb_poll_not_open', __( 'This poll isn\'t open yet.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			default:
				return new WP_Error( 'openbb_poll_closed', __( 'This poll is closed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! self::in_audience( $poll, $user_id ) ) {
			return new WP_Error( 'openbb_poll_audience', __( 'This poll is for members who ordered in particular cycles.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * The polls $user_id can answer now, by cycle, then oldest first.
	 *
	 * @return array[] Each array( 'poll' => OPENBB_Poll, 'cycle' => OPENBB_Cycle ).
	 */
	public static function open_for( int $user_id, ?int $timestamp = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->openbb_polls} WHERE status = %s ORDER BY cycle_id, id", OPENBB_Poll::STATUS_PUBLISHED ),
			ARRAY_A
		);

		$cycles = array();
		$open   = array();
		foreach ( (array) $rows as $row ) {
			$poll = new OPENBB_Poll( $row );
			if ( ! array_key_exists( $poll->cycle_id, $cycles ) ) {
				$cycles[ $poll->cycle_id ] = OPENBB_Cycles::get( $poll->cycle_id );
			}
			$cycle = $cycles[ $poll->cycle_id ];
			if ( $cycle && true === self::check_access( $poll, $user_id, $cycle, $timestamp ) ) {
				$open[] = array(
					'poll'  => $poll,
					'cycle' => $cycle,
				);
			}
		}

		return $open;
	}

	private static function stale(): WP_Error {
		return new WP_Error(
			'openbb_poll_stale',
			__( 'Someone else changed this poll since you opened it. Reload to see their changes, then make yours again.', 'open-bulk-buying' ),
			array( 'status' => 409 )
		);
	}

	private static function invalid( string $message, string $code = 'openbb_invalid_poll' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
