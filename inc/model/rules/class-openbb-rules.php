<?php
/**
 * Co-op rules: an editable draft (option) and an append-only table of published versions.
 *
 * Members store the id of the version they accepted, and are current when it is at
 * least the latest version that requires re-acceptance. Versions published with
 * requires_reacceptance = false (e.g. typo fixes, translation-only changes) replace
 * the displayed rules without invalidating earlier acceptances. Datetimes are UTC.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Rules {

	const DRAFT_OPTION = 'openbb_rules_draft';

	public static function schema(): array {
		global $wpdb;

		return array(
			$wpdb->openbb_rules_versions => "CREATE TABLE {$wpdb->openbb_rules_versions} (
				id bigint(20) unsigned NOT NULL auto_increment,
				content longtext NOT NULL,
				content_hash char(32) NOT NULL,
				requires_reacceptance tinyint(1) NOT NULL default 1,
				published_by bigint(20) unsigned NOT NULL default 0,
				published_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY requires_reacceptance (requires_reacceptance)
			) {$wpdb->get_charset_collate()};",
		);
	}

	/**
	 * An ordered array of localized HTML items. Legacy HTML maps become one item.
	 */
	public static function normalize_content( $content ): array {
		if ( is_string( $content ) || ( is_array( $content ) && isset( $content['default'] ) ) ) {
			$content = array( $content );
		}

		return array_values(
			array_map(
				static function ( $item ): array {
					$item = array_filter(
						openbb_sanitize_localized_html( $item ),
						static function ( string $text, string $language ): bool {
							return 'default' === $language || '' !== trim( $text );
						},
						ARRAY_FILTER_USE_BOTH
					);
					ksort( $item );
					return $item;
				},
				(array) $content
			)
		);
	}

	public static function hash_content( $content ): string {
		return md5( (string) wp_json_encode( self::normalize_content( $content ) ) );
	}

	public static function get( int $id ): ?OPENBB_Rules_Version {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_rules_versions} WHERE id = %d", $id ), ARRAY_A );

		return $row ? new OPENBB_Rules_Version( $row ) : null;
	}

	/**
	 * The rules currently shown to members; null until the first version is published.
	 */
	public static function get_latest(): ?OPENBB_Rules_Version {
		global $wpdb;

		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->openbb_rules_versions} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return $row ? new OPENBB_Rules_Version( $row ) : null;
	}

	/**
	 * Members must have accepted this version id or a later one. 0 when nothing is published.
	 */
	public static function get_required_version_id(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->openbb_rules_versions} WHERE requires_reacceptance = 1" );
	}

	/**
	 * Records that $user_id accepted published version $version_id, now. A persistence
	 * primitive: callers check that the person themselves is accepting (signup, order form).
	 */
	public static function record_acceptance( int $user_id, int $version_id ): bool {
		if ( $version_id <= 0 || ! self::get( $version_id ) ) {
			return false;
		}

		$ok = openbb_update_member_field( $user_id, 'rules_version', $version_id )
			&& openbb_update_member_field( $user_id, 'rules_accepted_at', current_time( 'mysql', true ) );
		if ( $ok ) {
			/**
			 * @param int $user_id
			 * @param int $version_id
			 */
			do_action( 'openbb_rules_accepted', $user_id, $version_id );
		}

		return $ok;
	}

	/**
	 * @return OPENBB_Rules_Version[] Newest first.
	 */
	public static function get_versions(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->openbb_rules_versions} ORDER BY id DESC", ARRAY_A );

		return array_map(
			static function ( array $row ): OPENBB_Rules_Version {
				return new OPENBB_Rules_Version( $row );
			},
			(array) $rows
		);
	}

	/**
	 * The draft being edited; starts from the latest published content.
	 */
	public static function get_draft(): array {
		$draft = get_option( self::DRAFT_OPTION, null );
		if ( is_array( $draft ) ) {
			return self::normalize_content( $draft );
		}

		$latest = self::get_latest();

		return $latest ? $latest->content : self::normalize_content( array() );
	}

	public static function save_draft( $content ): bool {
		$content = self::normalize_content( $content );

		return get_option( self::DRAFT_OPTION, null ) === $content || update_option( self::DRAFT_OPTION, $content, false );
	}

	/**
	 * Discards unpublished edits; published versions remain immutable.
	 */
	public static function discard_draft(): bool {
		return false === get_option( self::DRAFT_OPTION, false ) || delete_option( self::DRAFT_OPTION );
	}

	public static function has_unpublished_changes(): bool {
		$latest = self::get_latest();
		$draft  = self::get_draft();

		return $latest ? self::hash_content( $draft ) !== self::hash_content( $latest->content ) : ! empty( $draft );
	}

	/**
	 * Publishes the draft as a new version.
	 *
	 * The first version always requires acceptance; translation-only changes never do.
	 * Republishing identical content returns the latest version, unless it asks to
	 * require re-acceptance of a version that didn't.
	 *
	 * @return OPENBB_Rules_Version|WP_Error
	 */
	public static function publish( bool $requires_reacceptance, int $user_id ) {
		global $wpdb;

		$content = self::get_draft();
		if ( ! $content || array_filter( $content, static function ( array $item ): bool {
			return '' === trim( str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( $item['default'] ), ENT_QUOTES, 'UTF-8' ) ) );
		} ) ) {
			return new WP_Error( 'openbb_rules_empty', __( 'Every rule needs default text before the rules can be published.', 'open-bulk-buying' ) );
		}

		$hash   = self::hash_content( $content );
		$latest = self::get_latest();

		if ( ! $latest ) {
			$requires_reacceptance = true;
		} elseif ( $hash === self::hash_content( $latest->content ) ) {
			if ( ! $requires_reacceptance || $latest->requires_reacceptance ) {
				return $latest;
			}
		} elseif ( array_column( $content, 'default' ) === array_column( $latest->content, 'default' ) ) {
			$requires_reacceptance = false;
		}

		$inserted = $wpdb->insert(
			$wpdb->openbb_rules_versions,
			array(
				'content'               => wp_json_encode( $content ),
				'content_hash'          => $hash,
				'requires_reacceptance' => (int) $requires_reacceptance,
				'published_by'          => $user_id,
				'published_at'          => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);

		$version = $inserted ? self::get( (int) $wpdb->insert_id ) : null;
		if ( ! $version ) {
			return new WP_Error( 'openbb_rules_publish_failed', __( 'The rules could not be published.', 'open-bulk-buying' ) );
		}

		return $version;
	}
}
