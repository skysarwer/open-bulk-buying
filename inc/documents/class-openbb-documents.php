<?php
/**
 * Renders printable documents as standalone HTML that staff print from the browser.
 *
 * Templates use tables and block layout only (no flex, grid or CSS variables) so the
 * same markup can later be fed to a server-side PDF renderer. Pages load no images or
 * other resources.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Documents {

	const TEMPLATES   = array( 'order-slip' );
	const PAPER_SIZES = array( 'letter', 'a4' );

	public static function register_hooks(): void {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve' ), 10, 2 );
	}

	/**
	 * Sends document responses as HTML instead of JSON.
	 *
	 * @param bool             $served
	 * @param WP_HTTP_Response $result
	 */
	public static function serve( $served, $result ): bool {
		if ( $served || ! $result instanceof WP_REST_Response || ! is_string( $result->get_data() )
			|| 0 !== strpos( (string) $result->get_matched_route(), '/' . OPENBB_REST_Controller::API_NAMESPACE . '/documents/' ) ) {
			return (bool) $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the templates.

		return true;
	}

	/**
	 * The document as an HTML REST response with headers that keep it private and inert.
	 */
	public static function response( OPENBB_Document $document, array $options = array() ): WP_REST_Response {
		$nonce    = bin2hex( random_bytes( 16 ) );
		$response = new WP_REST_Response( self::render( $document, $nonce, ! empty( $options['autoprint'] ) ) );

		$response->set_headers(
			array(
				'Content-Type'            => 'text/html; charset=utf-8',
				'Cache-Control'           => 'private, no-store, max-age=0',
				'X-Content-Type-Options'  => 'nosniff',
				'X-Robots-Tag'            => 'noindex, nofollow',
				'Referrer-Policy'         => 'no-referrer',
				'X-Frame-Options'         => 'DENY',
				'Content-Security-Policy' => "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			)
		);

		return $response;
	}

	/**
	 * The full HTML page. The toolbar and title use the viewer's language; each page
	 * switches to its own locale.
	 */
	public static function render( OPENBB_Document $document, string $nonce, bool $autoprint = false ): string {
		$site_name = self::site_name();
		$pages     = array();
		foreach ( $document->pages() as $page ) {
			if ( ! in_array( $page['template'], self::TEMPLATES, true ) ) {
				throw new InvalidArgumentException( 'Unknown co-op document template: ' . $page['template'] );
			}
			$switched = switch_to_locale( $page['locale'] );
			try {
				$pages[] = self::template( $page['template'], array(
					'data'      => ( $page['data'] )(),
					'site_name' => $site_name,
				) );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}

		$switched = switch_to_locale( get_user_locale() );
		try {
			return self::template( 'layout', array(
				'title'     => $document->title(),
				'type'      => $document->type(),
				'pages'     => $pages,
				'paper'     => self::paper_size(),
				'css'       => (string) file_get_contents( __DIR__ . '/document.css' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'nonce'     => $nonce,
				'autoprint' => $autoprint,
				'lang'      => str_replace( '_', '-', get_locale() ),
			) );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	public static function site_name(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function paper_size(): string {
		$size = OPENBB_Settings::get_general()['document_paper_size'];
		return in_array( $size, self::PAPER_SIZES, true ) ? $size : OPENBB_Settings::default_paper_size();
	}

	private static function template( string $name, array $vars ): string {
		ob_start();
		try {
			( static function ( string $file, array $vars ): void {
				require $file;
			} )( __DIR__ . '/templates/' . $name . '.php', $vars );
		} catch ( Throwable $error ) {
			ob_end_clean();
			throw $error;
		}

		return (string) ob_get_clean();
	}
}
