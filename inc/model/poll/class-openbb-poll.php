<?php
/**
 * Poll record: a set of questions staff ask members, often related to a cycle.
 *
 * A poll belongs to one cycle but not to its orders: members answer whether or not they
 * order, and when a poll can be answered follows its own rules, not the ordering window.
 *
 * Staff control the status (draft, published, closed). A published poll can be answered
 * while its opening rule has been met and its closing rule hasn't; phase() is that
 * computed state. Stage rules are read from the cycle's current stage on each request, so
 * nothing depends on a scheduled job, and moving a cycle back a stage re-evaluates the
 * poll. Closing a poll by hand is final.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Poll extends OPENBB_Entity {

	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';
	const STATUS_CLOSED    = 'closed';
	const STATUSES         = array( self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CLOSED );

	/** Opens as soon as it's published. */
	const RULE_PUBLISH = 'publish';
	/** Stays open until staff close it. */
	const RULE_MANUAL = 'manual';
	/** Opens or closes when the cycle reaches a stage. */
	const RULE_STAGE = 'stage';
	/** Opens or closes at a date and time. */
	const RULE_DATE = 'date';

	const OPEN_RULES  = array( self::RULE_STAGE, self::RULE_PUBLISH, self::RULE_DATE );
	const CLOSE_RULES = array( self::RULE_STAGE, self::RULE_MANUAL, self::RULE_DATE );

	const AUDIENCE_ALL     = 'all';
	const AUDIENCE_ORDERED = 'ordered_in_cycles';
	const AUDIENCES        = array( self::AUDIENCE_ALL, self::AUDIENCE_ORDERED );

	/** A card on the order page inviting the member to answer. */
	const PLACEMENT_CARD = 'card';
	/** The questions themselves in the order form's Finalize step. */
	const PLACEMENT_FINALIZE = 'finalize';
	const PLACEMENTS         = array( self::PLACEMENT_CARD, self::PLACEMENT_FINALIZE );

	const PHASE_DRAFT     = 'draft';
	const PHASE_SCHEDULED = 'scheduled';
	const PHASE_OPEN      = 'open';
	const PHASE_ENDED     = 'ended';
	const PHASE_CLOSED    = 'closed';

	public $id              = 0;
	public $cycle_id        = 0;
	public $title           = array();
	public $intro           = array();
	public $status          = self::STATUS_DRAFT;
	public $open_rule       = self::RULE_STAGE;
	public $open_stage      = OPENBB_Cycle::STATUS_ORDERING;
	public $open_at         = null;
	public $close_rule      = self::RULE_STAGE;
	public $close_stage     = OPENBB_Cycle::STATUS_PROCESSING;
	public $close_at        = null;
	public $placements      = array( self::PLACEMENT_CARD );
	public $audience        = self::AUDIENCE_ALL;
	public $audience_cycles = array();
	public $questions       = array();
	public $revision        = 0;
	public $created_at      = null;
	public $updated_at      = null;
	public $created_by      = null;
	public $published_at    = null;
	public $closed_at       = null;
	public $closed_by       = null;

	protected function cast(): void {
		$this->id         = (int) $this->id;
		$this->cycle_id   = (int) $this->cycle_id;
		$this->revision   = (int) $this->revision;
		$this->created_by = self::nullable_int( $this->created_by );
		$this->closed_by  = self::nullable_int( $this->closed_by );
		$this->title      = self::localized_field( $this->title );
		$this->intro      = self::localized_field( $this->intro );
		$this->placements = array_values( array_intersect( self::PLACEMENTS, array_map( 'strval', self::decode_json( $this->placements ) ) ) );

		$this->audience_cycles = array_values( array_unique( array_filter( array_map( 'absint', self::decode_json( $this->audience_cycles ) ) ) ) );

		$questions = array();
		foreach ( self::decode_json( $this->questions ) as $question ) {
			$question = is_array( $question ) ? OPENBB_Polls::normalize_question( $question ) : null;
			if ( $question && true === OPENBB_Polls::validate_question( $question ) ) {
				$questions[] = $question;
			}
		}
		$this->questions = $questions;
	}

	/**
	 * A localized map from a database row (JSON), a map, or plain text for the default language.
	 */
	private static function localized_field( $value ): array {
		$decoded = is_string( $value ) ? json_decode( $value, true ) : $value;

		return openbb_normalize_localized( is_array( $decoded ) ? $decoded : (string) $value );
	}

	public function save(): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( ! $this->created_at ) {
			$this->created_at = $now;
		}
		$this->updated_at = $now;

		$json = array();
		foreach ( array( 'title', 'intro', 'placements', 'audience_cycles', 'questions' ) as $field ) {
			$json[ $field ] = wp_json_encode( $this->$field );
			if ( false === $json[ $field ] ) {
				return false;
			}
		}

		return $this->save_row(
			$wpdb->openbb_polls,
			array(
				'cycle_id'        => $this->cycle_id,
				'title'           => $json['title'],
				'intro'           => $json['intro'],
				'status'          => $this->status,
				'open_rule'       => $this->open_rule,
				'open_stage'      => $this->open_stage,
				'open_at'         => $this->open_at,
				'close_rule'      => $this->close_rule,
				'close_stage'     => $this->close_stage,
				'close_at'        => $this->close_at,
				'placements'      => $json['placements'],
				'audience'        => $this->audience,
				'audience_cycles' => $json['audience_cycles'],
				'questions'       => $json['questions'],
				'revision'        => $this->revision,
				'created_at'      => $this->created_at,
				'updated_at'      => $this->updated_at,
				'created_by'      => $this->created_by,
				'published_at'    => $this->published_at,
				'closed_at'       => $this->closed_at,
				'closed_by'       => $this->closed_by,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
	}

	public function get_title( ?string $language = null ): string {
		return openbb_localized( $this->title, $language );
	}

	public function get_intro( ?string $language = null ): string {
		return openbb_localized( $this->intro, $language );
	}

	/**
	 * Every question, including archived ones, keyed by question key.
	 */
	public function questions_by_key(): array {
		return array_column( $this->questions, null, 'key' );
	}

	/**
	 * The questions members are asked, keyed by question key.
	 */
	public function active_questions(): array {
		return array_filter(
			$this->questions_by_key(),
			static function ( array $question ): bool {
				return ! $question['archived'];
			}
		);
	}

	public function has_placement( string $placement ): bool {
		return in_array( $placement, $this->placements, true );
	}

	/**
	 * Whether the opening rule has been met at $timestamp (default: now).
	 */
	public function has_opened( OPENBB_Cycle $cycle, ?int $timestamp = null ): bool {
		switch ( $this->open_rule ) {
			case self::RULE_PUBLISH:
				return true;
			case self::RULE_DATE:
				return $this->open_at && ( $timestamp ?? time() ) >= strtotime( $this->open_at . ' UTC' );
			default:
				return $cycle->has_reached( (string) $this->open_stage );
		}
	}

	/**
	 * Whether the closing rule has been met at $timestamp (default: now).
	 */
	public function has_ended( OPENBB_Cycle $cycle, ?int $timestamp = null ): bool {
		switch ( $this->close_rule ) {
			case self::RULE_MANUAL:
				return false;
			case self::RULE_DATE:
				return $this->close_at && ( $timestamp ?? time() ) >= strtotime( $this->close_at . ' UTC' );
			default:
				return $cycle->has_reached( (string) $this->close_stage );
		}
	}

	/**
	 * Where the poll stands at $timestamp (default: now): one of the PHASE_ constants.
	 * "Ended" is a published poll whose closing rule has been met; unlike "closed" it can
	 * reopen if staff change the rule or move the cycle back.
	 */
	public function phase( OPENBB_Cycle $cycle, ?int $timestamp = null ): string {
		if ( self::STATUS_PUBLISHED !== $this->status ) {
			return self::STATUS_CLOSED === $this->status ? self::PHASE_CLOSED : self::PHASE_DRAFT;
		}
		if ( $this->has_ended( $cycle, $timestamp ) ) {
			return self::PHASE_ENDED;
		}

		return $this->has_opened( $cycle, $timestamp ) ? self::PHASE_OPEN : self::PHASE_SCHEDULED;
	}

	/**
	 * Whether members can answer at $timestamp (default: now).
	 */
	public function is_open( OPENBB_Cycle $cycle, ?int $timestamp = null ): bool {
		return self::PHASE_OPEN === $this->phase( $cycle, $timestamp );
	}

	/**
	 * Translated status labels. Only call from UI code.
	 */
	public static function phase_labels(): array {
		return array(
			self::PHASE_DRAFT     => __( 'Draft', 'open-bulk-buying' ),
			self::PHASE_SCHEDULED => __( 'Scheduled', 'open-bulk-buying' ),
			self::PHASE_OPEN      => __( 'Open', 'open-bulk-buying' ),
			self::PHASE_ENDED     => __( 'Ended', 'open-bulk-buying' ),
			self::PHASE_CLOSED    => __( 'Closed', 'open-bulk-buying' ),
		);
	}
}
