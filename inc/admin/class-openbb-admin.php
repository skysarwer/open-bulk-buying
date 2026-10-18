<?php
/**
 * Co-op admin screens: a "Co-op" menu whose pages all mount the React admin app
 * (src/admin, built to build/admin). The app's router switches between screens
 * client-side; each page still loads on its own.
 *
 * Every screen talks to the openbb/v1 REST API, which enforces permissions; the
 * menu capabilities here only decide what is shown.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Admin {

	const MENU_SLUG = 'openbb';

	/**
	 * Screen => [ page slug, capability ], in menu order. Every page mounts the same
	 * app, whose router switches between them without reloading.
	 */
	const SCREENS = array(
		'overview' => array( self::MENU_SLUG, 'openbb_access_admin' ),
		'products' => array( 'openbb-products', 'openbb_manage_products' ),
		'cycles'   => array( 'openbb-cycles', 'openbb_manage_cycles' ),
		'orders'   => array( 'openbb-orders', 'openbb_view_orders' ),
		'members'  => array( 'openbb-members', 'openbb_view_members' ),
		'settings' => array( 'open-bulk-buying', 'openbb_access_settings' ),
	);

	/** @var array<string, string> Hook suffix => screen. */
	private static $hooks = array();

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_menu(): void {
		$hook = add_menu_page(
			__( 'Co-op', 'open-bulk-buying' ),
			__( 'Co-op', 'open-bulk-buying' ),
			'openbb_access_admin',
			self::MENU_SLUG,
			self::render_callback( 'overview' ),
			'dashicons-carrot',
			26
		);
		if ( $hook ) {
			self::$hooks[ $hook ] = 'overview';
		}

		foreach ( self::screen_titles() as $screen => $title ) {
			list( $slug, $cap ) = self::SCREENS[ $screen ];

			$hook = add_submenu_page( self::MENU_SLUG, $title, $title, $cap, $slug, self::render_callback( $screen ) );
			if ( $hook ) {
				self::$hooks[ $hook ] = $screen;
			}
		}
	}

	public static function screen_url( string $screen, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SCREENS[ $screen ][0] ), $args ), admin_url( 'admin.php' ) );
	}

	private static function screen_titles(): array {
		return array(
			'overview' => __( 'Overview', 'open-bulk-buying' ),
			'products' => __( 'Products', 'open-bulk-buying' ),
			'cycles'   => __( 'Cycles', 'open-bulk-buying' ),
			'orders'   => __( 'Orders', 'open-bulk-buying' ),
			'members'  => __( 'Members', 'open-bulk-buying' ),
			'settings' => __( 'Settings', 'open-bulk-buying' ),
		);
	}

	private static function render_callback( string $screen ): Closure {
		// The Overview submenu shares the top-level hook; the same closure keeps add_action from rendering twice.
		static $callbacks = array();
		if ( ! isset( $callbacks[ $screen ] ) ) {
			$callbacks[ $screen ] = static function () use ( $screen ): void {
				self::render_screen( $screen );
			};
		}

		return $callbacks[ $screen ];
	}

	private static function render_screen( string $screen ): void {
		if ( ! current_user_can( self::SCREENS[ $screen ][1] ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'open-bulk-buying' ) );
		}
		?>
		<div class="wrap openbb-admin">
			<div id="openbb-admin-root" data-screen="<?php echo esc_attr( $screen ); ?>">
				<h1><?php echo esc_html( self::screen_titles()[ $screen ] ); ?></h1>
				<?php if ( ! file_exists( OPENBB_PLUGIN_DIR . 'build/admin/index.asset.php' ) ) : ?>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'The admin scripts have not been built. Run "npm install" and "npm run build" in the plugin folder.', 'open-bulk-buying' ); ?></p>
					</div>
				<?php else : ?>
					<p class="hide-if-js"><?php esc_html_e( 'This screen requires JavaScript.', 'open-bulk-buying' ); ?></p>
					<p class="hide-if-no-js"><?php esc_html_e( 'Loading…', 'open-bulk-buying' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
	public static function enqueue( string $hook_suffix ): void {
		if ( ! isset( self::$hooks[ $hook_suffix ] ) ) {
			return;
		}

		$asset_file = OPENBB_PLUGIN_DIR . 'build/admin/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset  = require $asset_file;
		$screen = self::$hooks[ $hook_suffix ];

		// Any screen can switch to Products without a reload, so load media up front.
		if ( current_user_can( 'openbb_manage_rules' ) ) {
			wp_enqueue_editor();
		}

		if ( current_user_can( 'openbb_manage_products' ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( 'openbb-admin', OPENBB_PLUGIN_URL . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'openbb-admin', 'open-bulk-buying' );
		wp_add_inline_script( 'openbb-admin', 'window.openbbAdmin = ' . wp_json_encode( self::boot_data( $screen ) ) . ';', 'before' );

		if ( file_exists( OPENBB_PLUGIN_DIR . 'build/admin/style-index.css' ) ) {
			wp_enqueue_style( 'openbb-admin', OPENBB_PLUGIN_URL . 'build/admin/style-index.css', array( 'wp-components' ), $asset['version'] );
			wp_style_add_data( 'openbb-admin', 'rtl', 'replace' );
		} else {
			wp_enqueue_style( 'wp-components' );
		}
	}

	/**
	 * Labels and settings the screens need. Permissions are re-checked by the API.
	 */
	private static function boot_data( string $screen ): array {
		$caps = array();
		foreach (
			array(
				'openbb_manage_products',
				'openbb_manage_inventory',
				'openbb_manage_cycles',
				'openbb_view_orders',
				'openbb_edit_orders',
				'openbb_view_members',
				'openbb_edit_members',
				'openbb_view_aid',
				'openbb_manage_access',
				'openbb_manage_settings',
				'openbb_manage_profile_fields',
				'openbb_manage_rules',
				'manage_options',
				'upload_files',
			) as $cap
		) {
			$caps[ $cap ] = current_user_can( $cap );
		}

		$urls    = array();
		$screens = array();
		foreach ( self::screen_titles() as $name => $title ) {
			list( $slug, $cap ) = self::SCREENS[ $name ];
			$urls[ $name ]      = self::screen_url( $name );
			if ( current_user_can( $cap ) ) {
				$screens[] = array(
					'name'  => $name,
					'slug'  => $slug,
					'title' => $title,
					'url'   => $urls[ $name ],
				);
			}
		}

		$languages = array();
		foreach ( openbb_languages() as $language ) {
			$languages[] = $language;
		}

		return array(
			'screen'               => $screen,
			'currency'             => openbb_currency(),
			'locale'               => str_replace( '_', '-', get_user_locale() ),
			'unitTypes'            => openbb_unit_types(),
			'unitConversions'      => openbb_unit_conversions(),
			'languages'            => $languages,
			'translationLanguages' => OPENBB_REST_Controller::translation_languages(),
			'coopRoles'            => OPENBB_Access::role_labels(),
			'staffRoles'           => OPENBB_Access::STAFF_ROLES,
			'cycleStatuses'        => OPENBB_Cycle::status_labels(),
			'orderStatuses'        => OPENBB_Order::status_labels(),
			'stockReasons'         => OPENBB_Stock_Move::reason_labels(),
			'manualStockReasons'   => OPENBB_Stock_Move::MANUAL_REASONS,
			'caps'                 => $caps,
			'urls'                 => $urls,
			'screens'              => $screens,
		);
	}
}
