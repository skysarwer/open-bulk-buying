<?php
/**
 * Members' answers to polls.
 *
 * A response is one member's answers to one poll. A response only exists while it
 * holds at least one answer.
 *
 * Answers are one row per selected option (or per text), which keeps "No" apart from
 * "not answered": a true/false question stores "1" or "0", and no row means no answer.
 *
 * Results come in two forms on purpose. tally() has counts only, with no identities and
 * no free text. responses() is who answered what. Keep them apart: anything shown beyond
 * staff must be built from tally() alone.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Poll_Responses {

	const YES = '1';
	const NO  = '0';

	/**
	 * A member's answers, keyed by question key: a string for text, a bool for yes/no and
	 * a list of option keys for options. Questions they didn't answer are left out.
	 */
	public static function get_answers( OPENBB_Poll $poll, int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.response_id, a.question_key, a.value_key, a.value_text
				FROM {$wpdb->openbb_poll_answers} a
				INNER JOIN {$wpdb->openbb_poll_responses} r ON r.id = a.response_id
				WHERE r.poll_id = %d AND r.user_id = %d
				ORDER BY a.id",
				$poll->id,
				$user_id
			),
			ARRAY_A
		);

		return current( self::group_answers( $poll, (array) $rows ) ) ?: array();
	}

	/**
	 * The polls among $poll_ids that $user_id has responded to.
	 *
	 * @param int[] $poll_ids
	 * @return int[]
	 */
	public static function responded( int $user_id, array $poll_ids ): array {
		global $wpdb;

		$poll_ids = array_values( array_filter( array_map( 'absint', $poll_ids ) ) );
		if ( ! $poll_ids ) {
			return array();
		}

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT poll_id FROM {$wpdb->openbb_poll_responses}
					WHERE user_id = %d AND poll_id IN (" . implode( ',', array_fill( 0, count( $poll_ids ), '%d' ) ) . ')',
					...array_merge( array( $user_id ), $poll_ids )
				)
			)
		);
	}

	/**
	 * Saves a member's answers to an open poll, replacing their earlier ones. Members can
	 * change their answers until the poll closes; the last save wins.
	 *
	 * $answers is question key => value for the questions being asked. A question left
	 * out or sent empty ('' / null / an empty list) is unanswered. Nothing is required,
	 * but a first response needs at least one answer, and clearing every answer removes
	 * the response. Answers to questions archived since are kept as they are.
	 *
	 * Answers are checked against the poll as it is now, so a form built from an older
	 * version fails clearly. Errors about a question have data 'errors' => key => message.
	 *
	 * Not for use inside another transaction: MySQL commits the outer one on START TRANSACTION.
	 *
	 * @return array|WP_Error array( 'responded' => bool, 'answers' => array ).
	 */
	public static function save( OPENBB_Poll $poll, int $user_id, array $answers ) {
		global $wpdb;

		$allowed = OPENBB_Polls::check_access( $poll, $user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$response_id = self::response_id( $poll->id, $user_id );
		$existing    = $response_id ? self::get_answers( $poll, $user_id ) : array();
		$questions   = $poll->active_questions();
		$clean       = array();
		$errors      = array();
		foreach ( $answers as $key => $value ) {
			$key      = (string) $key;
			$question = $questions[ $key ] ?? null;
			if ( ! $question ) {
				$errors[ $key ] = __( 'This question is no longer part of the poll. Reload the page and answer again.', 'open-bulk-buying' );
				continue;
			}
			if ( self::is_blank( $value ) ) {
				continue;
			}

			// An option archived since stays valid for a member who already chose it.
			$kept   = OPENBB_Field_Schema::TYPE_OPTIONS === $question['type'] ? (array) ( $existing[ $key ] ?? array() ) : array();
			$result = OPENBB_Field_Schema::sanitize_value( $question, $value, $kept );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}

			/**
			 * Filters whether a sanitized poll answer is valid. Return a WP_Error or false
			 * to reject it.
			 *
			 * @param true|WP_Error|false $valid
			 * @param array               $question
			 * @param mixed               $value    Sanitized value.
			 * @param OPENBB_Poll         $poll
			 */
			$valid = apply_filters( 'openbb_poll_answer_valid', true, $question, $result, $poll );
			if ( true !== $valid ) {
				$errors[ $key ] = is_wp_error( $valid ) ? $valid->get_error_message() : __( 'This value isn\'t accepted.', 'open-bulk-buying' );
				continue;
			}

			if ( ! self::is_blank( $result ) ) {
				$clean[ $key ] = $result;
			}
		}
		if ( $errors ) {
			return new WP_Error(
				'openbb_poll_answers_invalid',
				__( 'Some answers need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		if ( ! $clean && ! $response_id ) {
			return new WP_Error( 'openbb_poll_empty', __( 'Answer at least one question.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$failed = new WP_Error( 'openbb_poll_save_failed', __( 'Your answers could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $failed;
		}
		if ( ! self::write( $poll, $user_id, $response_id, array_keys( $questions ), $clean ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $failed;
		}

		$saved     = self::get_answers( $poll, $user_id );
		$responded = (bool) self::response_id( $poll->id, $user_id );

		/**
		 * Fires after a member's answers to a poll are saved or removed.
		 *
		 * @param OPENBB_Poll $poll
		 * @param int         $user_id   The member.
		 * @param bool        $responded False when they cleared every answer.
		 */
		do_action( 'openbb_poll_response_saved', $poll, $user_id, $responded );

		return array(
			'responded' => $responded,
			'answers'   => $saved,
		);
	}

	/**
	 * Replaces the member's answers to $question_keys with $clean, inside the caller's transaction.
	 */
	private static function write( OPENBB_Poll $poll, int $user_id, int $response_id, array $question_keys, array $clean ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( ! $response_id ) {
			// The unique key makes a second response from a concurrent request an update of the first.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->openbb_poll_responses} (poll_id, user_id, created_at, updated_at) VALUES (%d, %d, %s, %s)
					ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
					$poll->id,
					$user_id,
					$now,
					$now
				)
			);
			$response_id = false === $inserted ? 0 : self::response_id( $poll->id, $user_id );
			if ( ! $response_id ) {
				return false;
			}
		} elseif ( false === $wpdb->update( $wpdb->openbb_poll_responses, array( 'updated_at' => $now ), array( 'id' => $response_id ), array( '%s' ), array( '%d' ) ) ) {
			return false;
		}

		if ( $question_keys ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->openbb_poll_answers}
					WHERE response_id = %d AND question_key IN (" . implode( ',', array_fill( 0, count( $question_keys ), '%s' ) ) . ')',
					...array_merge( array( $response_id ), array_map( 'strval', $question_keys ) )
				)
			);
			if ( false === $deleted ) {
				return false;
			}
		}

		foreach ( $clean as $key => $value ) {
			foreach ( self::rows_for( $value ) as $row ) {
				$row = array_merge(
					array(
						'response_id'  => $response_id,
						'poll_id'      => $poll->id,
						'question_key' => (string) $key,
					),
					$row
				);
				if ( false === $wpdb->insert( $wpdb->openbb_poll_answers, $row, array( '%d', '%d', '%s', '%s' ) ) ) {
					return false;
				}
			}
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_answers} WHERE response_id = %d", $response_id ) );

		return $remaining > 0 || false !== $wpdb->delete( $wpdb->openbb_poll_responses, array( 'id' => $response_id ), array( '%d' ) );
	}

	/**
	 * The answer rows a sanitized value is stored as.
	 */
	private static function rows_for( $value ): array {
		if ( is_bool( $value ) ) {
			return array( array( 'value_key' => $value ? self::YES : self::NO ) );
		}
		if ( is_array( $value ) ) {
			return array_map(
				static function ( string $option ): array {
					return array( 'value_key' => $option );
				},
				$value
			);
		}

		return array( array( 'value_text' => (string) $value ) );
	}

	/**
	 * Whether a submitted value means "no answer". Unlike a profile checkbox, false is an
	 * answer here ("No").
	 */
	private static function is_blank( $value ): bool {
		return null === $value || array() === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	private static function response_id( int $poll_id, int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->openbb_poll_responses} WHERE poll_id = %d AND user_id = %d", $poll_id, $user_id )
		);
	}

	/**
	 * Answer rows as response id => question key => value. Rows for questions that no
	 * longer exist are skipped.
	 *
	 * @param array[] $rows response_id, question_key, value_key, value_text.
	 */
	private static function group_answers( OPENBB_Poll $poll, array $rows ): array {
		$questions = $poll->questions_by_key();
		$grouped   = array();
		foreach ( $rows as $row ) {
			$question = $questions[ $row['question_key'] ] ?? null;
			if ( ! $question ) {
				continue;
			}
			$id  = (int) $row['response_id'];
			$key = $question['key'];
			switch ( $question['type'] ) {
				case OPENBB_Field_Schema::TYPE_BOOLEAN:
					$grouped[ $id ][ $key ] = self::YES === $row['value_key'];
					break;
				case OPENBB_Field_Schema::TYPE_OPTIONS:
					$grouped[ $id ][ $key ][] = (string) $row['value_key'];
					break;
				default:
					$grouped[ $id ][ $key ] = (string) $row['value_text'];
			}
		}

		return $grouped;
	}

	/**
	 * Number of responses to each poll.
	 *
	 * @param int[] $poll_ids
	 * @return array<int, int> Poll id => count; polls without responses are left out.
	 */
	public static function counts( array $poll_ids ): array {
		global $wpdb;

		$poll_ids = array_values( array_filter( array_map( 'absint', $poll_ids ) ) );
		if ( ! $poll_ids ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT poll_id, COUNT(*) AS total FROM {$wpdb->openbb_poll_responses}
				WHERE poll_id IN (" . implode( ',', array_fill( 0, count( $poll_ids ), '%d' ) ) . ')
				GROUP BY poll_id',
				...$poll_ids
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['poll_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Counts only: how many members responded and, per question, how many answered and how
	 * many chose each option. Holds no identities and no free text, so it is the only
	 * result data that could ever be shown beyond staff.
	 *
	 * @return array responses (int), questions: key => array( answered, counts: value => int,
	 *               and average for star ratings ). Text questions have no counts.
	 */
	public static function tally( OPENBB_Poll $poll ): array {
		global $wpdb;

		$answered = array();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT question_key, COUNT(DISTINCT response_id) AS total FROM {$wpdb->openbb_poll_answers} WHERE poll_id = %d GROUP BY question_key",
				$poll->id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$answered[ $row['question_key'] ] = (int) $row['total'];
		}

		$chosen = array();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT question_key, value_key, COUNT(*) AS total FROM {$wpdb->openbb_poll_answers}
				WHERE poll_id = %d AND value_key IS NOT NULL GROUP BY question_key, value_key",
				$poll->id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$chosen[ $row['question_key'] ][ (string) $row['value_key'] ] = (int) $row['total'];
		}

		$questions = array();
		foreach ( $poll->questions_by_key() as $key => $question ) {
			$entry = array( 'answered' => $answered[ $key ] ?? 0 );
			if ( OPENBB_Field_Schema::TYPE_TEXT !== $question['type'] ) {
				$values = OPENBB_Field_Schema::TYPE_BOOLEAN === $question['type']
					? array( self::YES, self::NO )
					: OPENBB_Field_Schema::option_keys( $question );

				$entry['counts'] = array();
				foreach ( $values as $value ) {
					$entry['counts'][ $value ] = $chosen[ $key ][ $value ] ?? 0;
				}
			}
			if ( OPENBB_Field_Schema::is_stars( $question ) ) {
				$total = array_sum( $entry['counts'] );
				$sum   = 0;
				foreach ( $entry['counts'] as $stars => $count ) {
					$sum += (int) $stars * $count;
				}
				$entry['average'] = $total > 0 ? round( $sum / $total, 2 ) : null;
			}
			$questions[ $key ] = $entry;
		}

		return array(
			'responses' => self::counts( array( $poll->id ) )[ $poll->id ] ?? 0,
			'questions' => $questions,
		);
	}

	/**
	 * Who answered what, most recently changed first. Identifiable personal data: staff only.
	 *
	 * @return array total (int), items: list of array( user_id, created_at, updated_at, answers ).
	 */
	public static function responses( OPENBB_Poll $poll, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$per_page  = max( 1, $per_page );
		$responses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, created_at, updated_at FROM {$wpdb->openbb_poll_responses}
				WHERE poll_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
				$poll->id,
				$per_page,
				( max( 1, $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);

		$answers = array();
		$ids     = array_map( 'intval', wp_list_pluck( (array) $responses, 'id' ) );
		if ( $ids ) {
			$answers = self::group_answers(
				$poll,
				(array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT response_id, question_key, value_key, value_text FROM {$wpdb->openbb_poll_answers}
						WHERE response_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id',
						...$ids
					),
					ARRAY_A
				)
			);
		}

		$items = array();
		foreach ( (array) $responses as $response ) {
			$items[] = array(
				'user_id'    => (int) $response['user_id'],
				'created_at' => $response['created_at'],
				'updated_at' => $response['updated_at'],
				'answers'    => $answers[ (int) $response['id'] ] ?? array(),
			);
		}

		return array(
			'total' => self::counts( array( $poll->id ) )[ $poll->id ] ?? 0,
			'items' => $items,
		);
	}

	/**
	 * Every response of a member on this site, with its poll, oldest first. Responses
	 * whose poll is gone are left out (deleting a poll deletes its responses).
	 *
	 * @return array[] Each array( 'poll' => OPENBB_Poll, 'updated_at' => string, 'answers' => array ).
	 */
	public static function for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT poll_id, updated_at FROM {$wpdb->openbb_poll_responses} WHERE user_id = %d ORDER BY id", $user_id ),
			ARRAY_A
		);

		$responses = array();
		foreach ( (array) $rows as $row ) {
			$poll = OPENBB_Polls::get( (int) $row['poll_id'] );
			if ( $poll ) {
				$responses[] = array(
					'poll'       => $poll,
					'updated_at' => $row['updated_at'],
					'answers'    => self::get_answers( $poll, $user_id ),
				);
			}
		}

		return $responses;
	}

	/**
	 * Deletes every poll answer of a member on this site. Nothing is kept: free text can
	 * identify someone, so answers are deleted rather than anonymised.
	 *
	 * @return int|false Responses deleted, or false on failure.
	 */
	public static function delete_for_user( int $user_id ) {
		global $wpdb;

		$responses = $wpdb->prepare( "SELECT id FROM {$wpdb->openbb_poll_responses} WHERE user_id = %d", $user_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $responses is prepared above.
		if ( false === $wpdb->query( "DELETE FROM {$wpdb->openbb_poll_answers} WHERE response_id IN ({$responses})" ) ) {
			return false;
		}

		return $wpdb->delete( $wpdb->openbb_poll_responses, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
