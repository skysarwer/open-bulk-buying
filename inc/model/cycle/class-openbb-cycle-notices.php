<?php
/**
 * Cycle notices: short messages staff attach to a cycle for members, with an optional
 * call-to-action link.
 *
 * Notices are stored as a JSON list on the cycle row, so they live and die with the cycle.
 * OPENBB_Cycle::save() never writes the list; every change goes through here, as a
 * compare-and-swap on the stored JSON, so two staff editing different notices (or a stale
 * cycle save) can't overwrite each other. Each notice also has a revision, and saving from
 * an older revision is a conflict, as it is for polls.
 *
 * Staff control the status (draft or published). A published notice shows while its opening
 * rule has been met and its closing rule hasn't; phase() is that computed state. Like a
 * poll's rules, stage rules are read from the cycle's current stage on each request, so
 * nothing depends on a scheduled job. Unlike a poll, a published notice can go back to
 * draft: it holds no member data.
 *
 * Two kinds exist, matching the order page's notice styles: "info" (blue) and "alert"
 * (yellow). Green and red are reserved for success and error states.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Cycle_Notices {

	const TYPE_INFO  = 'info';
	const TYPE_ALERT = 'alert';
	const TYPES      = array( self::TYPE_INFO, self::TYPE_ALERT );

	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';
	const STATUSES         = array( self::STATUS_DRAFT, self::STATUS_PUBLISHED );

	/** Opens as soon as it's published. */
	const RULE_PUBLISH = 'publish';
	/** Stays up until staff unpublish it. */
	const RULE_MANUAL = 'manual';
	/** Opens or closes when the cycle reaches a stage. */
	const RULE_STAGE = 'stage';
	/** Opens or closes at a date and time. */
	const RULE_DATE = 'date';

	const OPEN_RULES  = array( self::RULE_PUBLISH, self::RULE_STAGE, self::RULE_DATE );
	const CLOSE_RULES = array( self::RULE_MANUAL, self::RULE_STAGE, self::RULE_DATE );

	const PHASE_DRAFT     = 'draft';
	const PHASE_SCHEDULED = 'scheduled';
	const PHASE_OPEN      = 'open';
	const PHASE_ENDED     = 'ended';

	const CONTENT_MAX   = 1000;
	const CTA_TEXT_MAX  = 60;
	const CTA_URL_MAX   = 2048;
	const MAX_PER_CYCLE = 20;

	/** Link schemes a call to action may use. */
	const CTA_PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	const ID_PATTERN = '/^[a-z0-9]{8,32}$/';

	/** Tries at a compare-and-swap before giving up to a concurrent writer. */
	const WRITE_ATTEMPTS = 5;

	/**
	 * A new notice: an info notice that shows once published, until unpublished.
	 */
	private static function defaults(): array {
		return array(
			'id'         => '',
			'type'       => self::TYPE_INFO,
			'content'    => array( 'default' => '' ),
			'status'     => self::STATUS_DRAFT,
			'open_rule'  => self::RULE_PUBLISH,
			'open_stage' => OPENBB_Cycle::STATUS_ORDERING,
			'open_at'    => null,
			'close_rule' => self::RULE_MANUAL,
			'close_stage' => OPENBB_Cycle::STATUS_PROCESSING,
			'close_at'   => null,
			'cta_text'   => array( 'default' => '' ),
			'cta_url'    => '',
			'revision'   => 0,
			'created_at' => null,
			'updated_at' => null,
		);
	}

	/**
	 * Reads a cycle's stored notices (JSON, or a list already decoded) leniently: entries
	 * without a valid id are dropped and invalid settings fall back to their defaults.
	 */
	public static function decode( $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		$notices = array();
		foreach ( is_array( $value ) ? $value : array() as $raw ) {
			$notice = is_array( $raw ) ? self::normalize( $raw ) : null;
			if ( $notice ) {
				$notices[] = $notice;
			}
		}

		return $notices;
	}

	private static function normalize( array $raw ): ?array {
		$defaults = self::defaults();
		if ( ! isset( $raw['id'] ) || ! is_string( $raw['id'] ) || ! preg_match( self::ID_PATTERN, $raw['id'] ) ) {
			return null;
		}

		$notice       = array_merge( $defaults, array_intersect_key( $raw, $defaults ) );
		$notice['id'] = $raw['id'];
		foreach ( array(
			'type'       => self::TYPES,
			'status'     => self::STATUSES,
			'open_rule'  => self::OPEN_RULES,
			'close_rule' => self::CLOSE_RULES,
			'open_stage' => OPENBB_Cycle::STATUSES,
			'close_stage' => OPENBB_Cycle::STATUSES,
		) as $field => $allowed ) {
			if ( ! is_string( $notice[ $field ] ) || ! in_array( $notice[ $field ], $allowed, true ) ) {
				$notice[ $field ] = $defaults[ $field ];
			}
		}
		foreach ( array( 'open_at', 'close_at', 'created_at', 'updated_at' ) as $field ) {
			$notice[ $field ] = is_string( $notice[ $field ] ) && '' !== $notice[ $field ] ? $notice[ $field ] : null;
		}
		$notice['content']  = openbb_normalize_localized( $notice['content'] );
		$notice['cta_text'] = openbb_normalize_localized( $notice['cta_text'] );
		$notice['cta_url']  = is_string( $notice['cta_url'] ) ? $notice['cta_url'] : '';
		$notice['revision'] = max( 1, (int) $notice['revision'] );

		return $notice;
	}

	public static function find( OPENBB_Cycle $cycle, string $id ): ?array {
		foreach ( $cycle->notices as $notice ) {
			if ( $notice['id'] === $id ) {
				return $notice;
			}
		}

		return null;
	}

	/**
	 * The notice's text in $language (default: the current language).
	 */
	public static function content( array $notice, ?string $language = null ): string {
		return openbb_localized( $notice['content'], $language );
	}

	/**
	 * The call to action as array( 'text', 'url' ), or null when the notice has none.
	 */
	public static function cta( array $notice, ?string $language = null ): ?array {
		$text = openbb_localized( $notice['cta_text'], $language );

		return '' !== $text && '' !== $notice['cta_url']
			? array(
				'text' => $text,
				'url'  => $notice['cta_url'],
			)
			: null;
	}

	/**
	 * Whether the opening rule has been met at $timestamp (default: now).
	 */
	public static function has_opened( array $notice, OPENBB_Cycle $cycle, ?int $timestamp = null ): bool {
		switch ( $notice['open_rule'] ) {
			case self::RULE_PUBLISH:
				return true;
			case self::RULE_DATE:
				return $notice['open_at'] && ( $timestamp ?? time() ) >= strtotime( $notice['open_at'] . ' UTC' );
			default:
				return $cycle->has_reached( (string) $notice['open_stage'] );
		}
	}

	/**
	 * Whether the closing rule has been met at $timestamp (default: now).
	 */
	public static function has_ended( array $notice, OPENBB_Cycle $cycle, ?int $timestamp = null ): bool {
		switch ( $notice['close_rule'] ) {
			case self::RULE_MANUAL:
				return false;
			case self::RULE_DATE:
				return $notice['close_at'] && ( $timestamp ?? time() ) >= strtotime( $notice['close_at'] . ' UTC' );
			default:
				return $cycle->has_reached( (string) $notice['close_stage'] );
		}
	}

	/**
	 * Where the notice stands at $timestamp (default: now): one of the PHASE_ constants.
	 * "Ended" is a published notice whose closing rule has been met; it shows again if
	 * staff change the rule or move the cycle back.
	 */
	public static function phase( array $notice, OPENBB_Cycle $cycle, ?int $timestamp = null ): string {
		if ( self::STATUS_PUBLISHED !== $notice['status'] ) {
			return self::PHASE_DRAFT;
		}
		if ( self::has_ended( $notice, $cycle, $timestamp ) ) {
			return self::PHASE_ENDED;
		}

		return self::has_opened( $notice, $cycle, $timestamp ) ? self::PHASE_OPEN : self::PHASE_SCHEDULED;
	}

	/**
	 * The notices members see for $cycle now, in the order staff added them.
	 */
	public static function open( OPENBB_Cycle $cycle, ?int $timestamp = null ): array {
		return array_values(
			array_filter(
				$cycle->notices,
				static function ( array $notice ) use ( $cycle, $timestamp ): bool {
					return self::PHASE_OPEN === self::phase( $notice, $cycle, $timestamp );
				}
			)
		);
	}

	/**
	 * The notices members see now across every cycle that isn't complete, by cycle.
	 *
	 * @return array[] Each array( 'notice' => array, 'cycle' => OPENBB_Cycle ).
	 */
	public static function open_in_active_cycles( ?int $timestamp = null ): array {
		$open = array();
		foreach ( OPENBB_Cycles::get_active() as $cycle ) {
			foreach ( self::open( $cycle, $timestamp ) as $notice ) {
				$open[] = array(
					'notice' => $notice,
					'cycle'  => $cycle,
				);
			}
		}

		return $open;
	}

	/**
	 * Translated labels. Only call from UI code.
	 */
	public static function phase_labels(): array {
		return array(
			self::PHASE_DRAFT     => __( 'Draft', 'open-bulk-buying' ),
			self::PHASE_SCHEDULED => __( 'Scheduled', 'open-bulk-buying' ),
			self::PHASE_OPEN      => __( 'Open', 'open-bulk-buying' ),
			self::PHASE_ENDED     => __( 'Ended', 'open-bulk-buying' ),
		);
	}

	public static function type_labels(): array {
		return array(
			self::TYPE_INFO  => __( 'Info', 'open-bulk-buying' ),
			self::TYPE_ALERT => __( 'Alert', 'open-bulk-buying' ),
		);
	}

	/**
	 * Adds a notice to a cycle, or changes one. Only the fields in $input change.
	 *
	 * @param string|null $id            Null to add a notice.
	 * @param int         $base_revision The revision the editor loaded; ignored for a new notice.
	 * @return array|WP_Error The saved notice.
	 */
	public static function save( int $cycle_id, ?string $id, array $input, int $base_revision ) {
		return self::mutate(
			$cycle_id,
			static function ( array $notices ) use ( $id, $input, $base_revision ) {
				$index = null === $id ? null : self::index_of( $notices, $id );
				if ( null === $id ) {
					if ( count( $notices ) >= self::MAX_PER_CYCLE ) {
						return self::invalid(
							/* translators: %d: maximum number of notices. */
							sprintf( __( 'A cycle can have at most %d notices.', 'open-bulk-buying' ), self::MAX_PER_CYCLE )
						);
					}
					$notice               = self::defaults();
					$notice['id']         = self::new_id( $notices );
					$notice['created_at'] = current_time( 'mysql', true );
				} elseif ( null === $index ) {
					return self::missing();
				} elseif ( $notices[ $index ]['revision'] !== $base_revision ) {
					return self::stale();
				} else {
					$notice = $notices[ $index ];
				}

				$notice = self::validate( self::apply( $notice, $input ) );
				if ( is_wp_error( $notice ) ) {
					return $notice;
				}
				$notice['revision']  = $notice['revision'] + 1;
				$notice['updated_at'] = current_time( 'mysql', true );
				if ( null === $index ) {
					$notices[] = $notice;
				} else {
					$notices[ $index ] = $notice;
				}

				return array( $notices, $notice );
			}
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $cycle_id, string $id ) {
		$deleted = self::mutate(
			$cycle_id,
			static function ( array $notices ) use ( $id ) {
				$index = self::index_of( $notices, $id );
				if ( null === $index ) {
					return self::missing();
				}
				unset( $notices[ $index ] );

				return array( $notices, true );
			}
		);

		return is_wp_error( $deleted ) ? $deleted : true;
	}

	private static function index_of( array $notices, string $id ): ?int {
		foreach ( $notices as $index => $notice ) {
			if ( $notice['id'] === $id ) {
				return $index;
			}
		}

		return null;
	}

	private static function new_id( array $notices ): string {
		$taken = array_column( $notices, 'id' );
		do {
			$id = strtolower( wp_generate_password( 12, false ) );
		} while ( in_array( $id, $taken, true ) );

		return $id;
	}

	/**
	 * Copies the fields present in $input onto $notice, sanitized but not yet checked.
	 */
	private static function apply( array $notice, array $input ): array {
		foreach ( array( 'type', 'status', 'open_rule', 'open_stage', 'close_rule', 'close_stage' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$notice[ $field ] = is_string( $input[ $field ] ) ? $input[ $field ] : '';
			}
		}
		foreach ( array( 'open_at', 'close_at' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$notice[ $field ] = is_string( $input[ $field ] ) && '' !== $input[ $field ] ? $input[ $field ] : null;
			}
		}
		if ( array_key_exists( 'content', $input ) ) {
			$notice['content'] = self::translations( array_map( 'sanitize_textarea_field', openbb_normalize_localized( $input['content'] ) ) );
		}
		if ( array_key_exists( 'cta_text', $input ) ) {
			$notice['cta_text'] = self::translations( openbb_sanitize_localized_text( $input['cta_text'] ) );
		}
		if ( array_key_exists( 'cta_url', $input ) ) {
			$notice['cta_url'] = is_string( $input['cta_url'] ) ? trim( $input['cta_url'] ) : '';
		}

		return $notice;
	}

	/**
	 * A localized map without its empty translations: members get the default text there.
	 */
	private static function translations( array $text ): array {
		return array_filter(
			$text,
			static function ( string $value, string $language ): bool {
				return 'default' === $language || '' !== trim( $value );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Checks a notice's settings. Unused rule fields are reset so they can't hold stale values.
	 *
	 * @return array|WP_Error The notice, with its link cleaned.
	 */
	private static function validate( array $notice ) {
		if ( '' === trim( $notice['content']['default'] ) ) {
			return self::invalid( __( 'Write the notice text.', 'open-bulk-buying' ) );
		}
		foreach ( $notice['content'] as $text ) {
			if ( mb_strlen( $text ) > self::CONTENT_MAX ) {
				return self::invalid(
					/* translators: %d: maximum number of characters. */
					sprintf( __( 'The notice text can be at most %d characters.', 'open-bulk-buying' ), self::CONTENT_MAX )
				);
			}
		}
		if ( ! in_array( $notice['type'], self::TYPES, true ) ) {
			return self::invalid( __( 'Choose whether the notice is info or an alert.', 'open-bulk-buying' ) );
		}
		if ( ! in_array( $notice['status'], self::STATUSES, true ) ) {
			return self::invalid( __( 'A notice is either a draft or published.', 'open-bulk-buying' ) );
		}

		$timing = self::validate_timing( $notice );
		if ( is_wp_error( $timing ) ) {
			return $timing;
		}

		return self::validate_cta( $timing );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function validate_timing( array $notice ) {
		if ( ! in_array( $notice['open_rule'], self::OPEN_RULES, true ) || ! in_array( $notice['close_rule'], self::CLOSE_RULES, true ) ) {
			return self::invalid( __( 'Choose when the notice opens and closes.', 'open-bulk-buying' ) );
		}

		$stages = OPENBB_Cycle::STATUSES;
		foreach ( array( 'open', 'close' ) as $side ) {
			$rule  = $side . '_rule';
			$stage = $side . '_stage';
			$date  = $side . '_at';
			if ( self::RULE_STAGE !== $notice[ $rule ] ) {
				$notice[ $stage ] = 'open' === $side ? OPENBB_Cycle::STATUS_ORDERING : OPENBB_Cycle::STATUS_PROCESSING;
			} elseif ( ! in_array( $notice[ $stage ], $stages, true ) ) {
				return self::invalid( __( 'Choose a cycle stage.', 'open-bulk-buying' ) );
			}
			if ( self::RULE_DATE !== $notice[ $rule ] ) {
				$notice[ $date ] = null;
				continue;
			}
			$time = $notice[ $date ] ? strtotime( $notice[ $date ] . ' UTC' ) : false;
			if ( false === $time ) {
				return self::invalid(
					'open' === $side
						? __( 'Set the date and time the notice opens.', 'open-bulk-buying' )
						: __( 'Set the date and time the notice closes.', 'open-bulk-buying' )
				);
			}
			$notice[ $date ] = gmdate( 'Y-m-d H:i:s', $time );
		}

		if ( self::RULE_STAGE === $notice['close_rule'] ) {
			// Every cycle has reached its first stage, so the notice would never show.
			$first = self::RULE_STAGE === $notice['open_rule'] ? array_search( $notice['open_stage'], $stages, true ) : 0;
			if ( array_search( $notice['close_stage'], $stages, true ) <= $first ) {
				return self::invalid( __( 'The notice must close at a later cycle stage than the one it opens at.', 'open-bulk-buying' ) );
			}
		}
		if ( self::RULE_DATE === $notice['open_rule'] && self::RULE_DATE === $notice['close_rule']
			&& strtotime( $notice['close_at'] . ' UTC' ) <= strtotime( $notice['open_at'] . ' UTC' ) ) {
			return self::invalid( __( 'The notice must close after it opens.', 'open-bulk-buying' ) );
		}

		return $notice;
	}

	/**
	 * A call to action is a button text and a link, both or neither.
	 *
	 * @return array|WP_Error
	 */
	private static function validate_cta( array $notice ) {
		$text = trim( $notice['cta_text']['default'] );
		$url  = $notice['cta_url'];
		if ( '' === $text && '' === $url ) {
			$notice['cta_text'] = array( 'default' => '' );

			return $notice;
		}
		if ( '' === $text || '' === $url ) {
			return self::invalid( __( 'Add both the button text and its link, or leave both empty.', 'open-bulk-buying' ) );
		}
		foreach ( $notice['cta_text'] as $translation ) {
			if ( mb_strlen( $translation ) > self::CTA_TEXT_MAX ) {
				return self::invalid(
					/* translators: %d: maximum number of characters. */
					sprintf( __( 'The button text can be at most %d characters.', 'open-bulk-buying' ), self::CTA_TEXT_MAX )
				);
			}
		}

		$clean = strlen( $url ) > self::CTA_URL_MAX ? '' : esc_url_raw( $url, self::CTA_PROTOCOLS );
		if ( '' === $clean ) {
			return self::invalid( __( 'Enter a web address (http or https), email link (mailto:) or phone link (tel:) for the button.', 'open-bulk-buying' ) );
		}
		$notice['cta_url'] = $clean;

		return $notice;
	}

	/**
	 * Applies $change to the cycle's current notices and writes the result, retrying when
	 * another write lands in between.
	 *
	 * @param callable $change Receives the notices; returns array( $new_notices, $result ) or a WP_Error.
	 * @return mixed|WP_Error The result.
	 */
	private static function mutate( int $cycle_id, callable $change ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT notices FROM {$wpdb->openbb_cycles} WHERE id = %d", $cycle_id ), ARRAY_A );
			if ( ! $row ) {
				return new WP_Error( 'openbb_cycle_not_found', __( 'Cycle not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}

			$result = $change( self::decode( $row['notices'] ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			list( $notices, $value ) = $result;

			$json = wp_json_encode( array_values( $notices ) );
			if ( false === $json ) {
				return self::save_failed();
			}
			$swapped = self::swap( $cycle_id, $row['notices'], $json );
			if ( false === $swapped ) {
				return self::save_failed();
			}
			if ( 1 === $swapped ) {
				return $value;
			}
		}

		return self::stale();
	}

	/**
	 * Replaces the stored JSON only if it still is what the caller read.
	 *
	 * @param string|null $stored What the caller read.
	 * @return int|false Rows written: 1, or 0 when someone else wrote first; false on a database error.
	 */
	private static function swap( int $cycle_id, ?string $stored, string $json ) {
		global $wpdb;

		// BINARY: the column's collation ignores case and trailing spaces, which would
		// let a stale edit that differs only in those pass.
		if ( null === $stored ) {
			return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->openbb_cycles} SET notices = %s WHERE id = %d AND notices IS NULL", $json, $cycle_id ) );
		}

		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->openbb_cycles} SET notices = %s WHERE id = %d AND notices = BINARY %s", $json, $cycle_id, $stored ) );
	}

	private static function stale(): WP_Error {
		return new WP_Error(
			'openbb_notice_stale',
			__( 'Someone else changed this notice since you opened it. Reload to see their changes, then make yours again.', 'open-bulk-buying' ),
			array( 'status' => 409 )
		);
	}

	private static function missing(): WP_Error {
		return new WP_Error( 'openbb_notice_not_found', __( 'Notice not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
	}

	private static function save_failed(): WP_Error {
		return new WP_Error( 'openbb_notice_save_failed', __( 'The notice could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'openbb_invalid_notice', $message, array( 'status' => 400 ) );
	}
}
