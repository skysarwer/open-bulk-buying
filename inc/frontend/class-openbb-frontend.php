<?php
/**
 * Frontend assets and shortcodes.
 *
 * Views are server-rendered PHP with Interactivity API directives, processed by
 * wp_interactivity_process_directives() so the first paint is complete without JS.
 * The stores are plain ES modules in assets/js/ (no build step); styles are compiled
 * from src/sass/frontend.scss to build/frontend.css.
 *
 * Script modules:
 * - @open-bulk-buying/forms   Store `openbb/forms`: field state, validation, errors.
 * - @open-bulk-buying/signup  Store `openbb/signup`: the signup flow.
 * - @open-bulk-buying/login   Store `openbb/login`: password and email-link login.
 * - @open-bulk-buying/auth    Store `openbb/auth`: switches between the login and signup panels.
 * - @open-bulk-buying/captcha Bot protection widgets: renders Turnstile, collects and resets responses.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Frontend {

	const STYLE = 'openbb-frontend';

	const MODULE_FORMS   = '@open-bulk-buying/forms';
	const MODULE_SIGNUP  = '@open-bulk-buying/signup';
	const MODULE_LOGIN   = '@open-bulk-buying/login';
	const MODULE_AUTH    = '@open-bulk-buying/auth';
	const MODULE_CAPTCHA = '@open-bulk-buying/captcha';

	/** Shortcode tag => view class with a static render( array $args ): string. */
	const SHORTCODES = array(
		'openbb_signup' => 'OPENBB_Signup_View',
		'openbb_login'  => 'OPENBB_Login_View',
	);

	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ) );
	}

	public static function register_assets(): void {
		$modules = array(
			self::MODULE_FORMS   => array( 'forms.js', array( '@wordpress/interactivity' ) ),
			self::MODULE_CAPTCHA => array( 'captcha.js', array( '@wordpress/interactivity' ) ),
			self::MODULE_SIGNUP  => array( 'signup.js', array( '@wordpress/interactivity', self::MODULE_FORMS, self::MODULE_CAPTCHA ) ),
			self::MODULE_LOGIN   => array( 'login.js', array( '@wordpress/interactivity', self::MODULE_FORMS, self::MODULE_CAPTCHA ) ),
			self::MODULE_AUTH    => array( 'auth.js', array( '@wordpress/interactivity', self::MODULE_FORMS ) ),
		);
		foreach ( $modules as $id => list( $file, $deps ) ) {
			wp_register_script_module( $id, OPENBB_PLUGIN_URL . 'assets/js/' . $file, $deps, self::version( 'assets/js/' . $file ) );
		}

		wp_register_style( self::STYLE, OPENBB_PLUGIN_URL . 'build/frontend.css', array(), self::version( 'build/frontend.css' ) );
		wp_style_add_data( self::STYLE, 'rtl', 'replace' );
	}

	public static function register_shortcodes(): void {
		foreach ( self::SHORTCODES as $tag => $view ) {
			add_shortcode(
				$tag,
				static function ( $atts ) use ( $tag, $view ): string {
					return $view::render( shortcode_atts( $view::defaults(), (array) $atts, $tag ) );
				}
			);
		}
	}

	/**
	 * Pages with our views show personal data and carry nonces, so they must not be cached.
	 * Detected here, before headers are sent, for the queried post; views also call
	 * no_cache() themselves for other placements (e.g. widgets or templates).
	 */
	public static function prepare_page(): void {
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		foreach ( array_keys( self::SHORTCODES ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				self::no_cache();
				// Styles enqueued while the content renders would print in the footer of classic themes.
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
				return;
			}
		}
	}

	public static function enqueue_style(): void {
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Enqueues the style and the given script modules (their dependencies follow).
	 */
	public static function enqueue( array $modules ): void {
		self::enqueue_style();
		foreach ( $modules as $module ) {
			wp_enqueue_script_module( $module );
		}
	}

	public static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Runs the Interactivity API server-side rendering over a view's markup.
	 */
	public static function process( string $html ): string {
		return function_exists( 'wp_interactivity_process_directives' ) ? wp_interactivity_process_directives( $html ) : $html;
	}

	/**
	 * Renders inc/frontend/templates/$name.php with $view in scope.
	 */
	public static function template( string $name, array $view ): string {
		ob_start();
		require OPENBB_PLUGIN_DIR . 'inc/frontend/templates/' . $name . '.php';

		return (string) ob_get_clean();
	}

	/**
	 * Validation messages for the `openbb/forms` store.
	 */
	public static function forms_state(): void {
		wp_interactivity_state(
			'openbb/forms',
			array(
				'messages' => array(
					'required'      => __( 'This field is required.', 'open-bulk-buying' ),
					'requiredCheck' => __( 'This box must be checked.', 'open-bulk-buying' ),
					'requiredOne'   => __( 'Choose an option.', 'open-bulk-buying' ),
					'email'         => __( 'Enter a valid email address.', 'open-bulk-buying' ),
				),
			)
		);
	}

	/**
	 * The page a view is shown on: the queried post, else the home page.
	 */
	public static function current_url(): string {
		$post = get_post();

		return $post && is_singular() ? (string) get_permalink( $post ) : home_url( '/' );
	}

	/**
	 * $url when it's on this site, else the current page.
	 */
	public static function redirect_url( string $url ): string {
		$current = self::current_url();

		return '' !== $url ? wp_validate_redirect( $url, $current ) : $current;
	}

	private static function version( string $path ): string {
		$file = OPENBB_PLUGIN_DIR . $path;

		return file_exists( $file ) ? (string) filemtime( $file ) : OPENBB_VERSION;
	}
}
