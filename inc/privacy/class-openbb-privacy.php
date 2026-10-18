<?php
/**
 * Registers the plugin's privacy providers with the WordPress personal data exporter and
 * eraser tools (Tools > Export/Erase Personal Data).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Privacy {

	const KEY_PREFIX = 'open-bulk-buying-';

	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * Built-in providers, extendable through the `openbb_privacy_providers` filter.
	 *
	 * @return OPENBB_Privacy_Provider[] Keyed by id.
	 */
	public static function providers(): array {
		$providers = apply_filters(
			'openbb_privacy_providers',
			array(
				new OPENBB_Privacy_Profile_Fields(),
			)
		);

		$keyed = array();
		foreach ( (array) $providers as $provider ) {
			if ( $provider instanceof OPENBB_Privacy_Provider ) {
				$keyed[ $provider->id() ] = $provider;
			}
		}

		return $keyed;
	}

	public static function register_exporters( $exporters ): array {
		$exporters = (array) $exporters;
		foreach ( self::providers() as $id => $provider ) {
			$exporters[ self::KEY_PREFIX . $id ] = array(
				'exporter_friendly_name' => $provider->label(),
				'callback'               => static function ( $email, $page = 1 ) use ( $provider ): array {
					$user = get_user_by( 'email', (string) $email );
					if ( ! $user ) {
						return array(
							'data' => array(),
							'done' => true,
						);
					}

					return $provider->export( $user, max( 1, (int) $page ) );
				},
			);
		}

		return $exporters;
	}

	public static function register_erasers( $erasers ): array {
		$erasers = (array) $erasers;
		foreach ( self::providers() as $id => $provider ) {
			$erasers[ self::KEY_PREFIX . $id ] = array(
				'eraser_friendly_name' => $provider->label(),
				'callback'             => static function ( $email, $page = 1 ) use ( $provider ): array {
					$user = get_user_by( 'email', (string) $email );
					if ( ! $user ) {
						return array(
							'items_removed'  => false,
							'items_retained' => false,
							'messages'       => array(),
							'done'           => true,
						);
					}

					return $provider->erase( $user, max( 1, (int) $page ) );
				},
			);
		}

		return $erasers;
	}
}
