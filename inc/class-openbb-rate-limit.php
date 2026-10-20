<?php
/**
 * Fixed-window rate limits for routes open to visitors (signup now, login codes later),
 * keyed by a hash of the client IP so no addresses are stored.
 *
 * REMOTE_ADDR is used by default because forwarded headers can be spoofed. Sites behind a
 * reverse proxy can supply the real client address with the 'openbb_client_ip' filter.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Rate_Limit {

	/**
	 * Counts a hit for $bucket and reports whether the client is still within $limit hits
	 * per $window seconds. Returns a 429 WP_Error once the limit is exceeded.
	 *
	 * @return true|WP_Error
	 */
	public static function hit( string $bucket, int $limit, int $window ) {
		/**
		 * Filters a rate limit, e.g. to loosen it for a co-op whose members share one IP.
		 *
		 * @param int    $limit
		 * @param string $bucket
		 */
		$limit = (int) apply_filters( 'openbb_rate_limit', $limit, $bucket );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'openbb_rl_' . md5( $bucket . '|' . self::client_ip() );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || (int) ( $entry['reset'] ?? 0 ) <= time() ) {
			$entry = array(
				'count' => 0,
				'reset' => time() + $window,
			);
		}
		++$entry['count'];
		set_transient( $key, $entry, max( 1, $entry['reset'] - time() ) );

		if ( $entry['count'] > $limit ) {
			return new WP_Error(
				'openbb_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'open-bulk-buying' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $entry['reset'] - time() ),
				)
			);
		}

		return true;
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * @param string $ip The client IP; REMOTE_ADDR by default.
		 */
		return (string) apply_filters( 'openbb_client_ip', $ip );
	}
}
