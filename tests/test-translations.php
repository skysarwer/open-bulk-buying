<?php
/**
 * Bundled translation coverage, compiled catalogs, and locale switching.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Translations extends WP_UnitTestCase {

	private function catalog( string $name ): PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$catalog = new PO();
		$this->assertTrue( $catalog->import_from_file( OPENBB_PLUGIN_DIR . 'languages/' . $name ) );
		return $catalog;
	}

	private function token_matches( string $pattern, string $text ): array {
		preg_match_all( $pattern, $text, $matches );
		sort( $matches[0] );
		return $matches[0];
	}

	public function test_french_catalog_covers_the_template_without_fuzzy_entries(): void {
		$template = $this->catalog( 'open-bulk-buying.pot' );
		$french   = $this->catalog( 'open-bulk-buying-fr_CA.po' );

		$this->assertNotEmpty( $template->entries );
		$this->assertEqualsCanonicalizing( array_keys( $template->entries ), array_keys( $french->entries ) );
		$this->assertSame( 'fr_CA', $french->headers['Language'] );
		$this->assertSame( 'nplurals=2; plural=(n > 1);', $french->headers['Plural-Forms'] );

		foreach ( $template->entries as $key => $source ) {
			$entry = $french->entries[ $key ];
			$this->assertNotContains( 'fuzzy', $entry->flags, $key );
			$this->assertSame( $source->flags, $entry->flags, $key );
			$this->assertSame( $source->plural, $entry->plural, $key );
			$this->assertCount( $source->is_plural ? 2 : 1, $entry->translations, $key );

			foreach ( $entry->translations as $index => $translation ) {
				$original = $index ? $source->plural : $source->singular;
				$this->assertNotSame( '', $translation, $key );
				$this->assertSame(
					$this->token_matches( '/%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.\d+)?[bcdeEfFgGiosuxX]/', $original ),
					$this->token_matches( '/%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.\d+)?[bcdeEfFgGiosuxX]/', $translation ),
					$key
				);
				$this->assertSame(
					$this->token_matches( '/<\/?[a-z][^>]*>/i', $original ),
					$this->token_matches( '/<\/?[a-z][^>]*>/i', $translation ),
					$key
				);
				$this->assertSame( substr_count( $original, "\n" ), substr_count( $translation, "\n" ), $key );
			}
		}
	}

	public function test_compiled_mo_matches_every_po_translation(): void {
		$french = $this->catalog( 'open-bulk-buying-fr_CA.po' );
		$mo     = new MO();
		$this->assertTrue( $mo->import_from_file( OPENBB_PLUGIN_DIR . 'languages/open-bulk-buying-fr_CA.mo' ) );
		$this->assertCount( count( $french->entries ), $mo->entries );

		foreach ( $french->entries as $key => $entry ) {
			$this->assertArrayHasKey( $key, $mo->entries );
			$this->assertSame( $entry->translations, $mo->entries[ $key ]->translations, $key );
		}

		$this->assertSame( '%d article', $mo->translate_plural( '%d item', '%d items', 0 ) );
		$this->assertSame( '%d article', $mo->translate_plural( '%d item', '%d items', 1 ) );
		$this->assertSame( '%d articles', $mo->translate_plural( '%d item', '%d items', 2 ) );
	}

	public function test_javascript_catalog_covers_the_admin_bundle(): void {
		$french = $this->catalog( 'open-bulk-buying-fr_CA.po' );
		$file   = OPENBB_PLUGIN_DIR . 'languages/open-bulk-buying-fr_CA-' . md5( 'build/admin/index.js' ) . '.json';
		$this->assertFileExists( $file );
		$json = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'build/admin/index.js', $json['source'] );
		$messages = $json['locale_data']['messages'];
		$this->assertSame( 'fr_CA', $messages['']['lang'] );
		$this->assertSame( $french->headers['Plural-Forms'], $messages['']['plural-forms'] );
		$count = 0;

		foreach ( $french->entries as $key => $entry ) {
			if ( ! preg_grep( '/^src\/admin\/.*\.js(?::\d+)?$/', $entry->references ) ) {
				continue;
			}
			++$count;
			$this->assertArrayHasKey( $key, $messages );
			$this->assertSame( $entry->translations, $messages[ $key ], $key );
		}
		$this->assertGreaterThan( 0, $count );
		$this->assertCount( $count + 1, $messages );
	}

	public function test_wordpress_loads_bundled_php_and_script_translations_and_restores_english(): void {
		$original = __( 'Products', 'open-bulk-buying' );
		// The core test fixtures do not include a Canadian French language pack.
		$languages = static function ( array $available ): array {
			$available[] = 'fr_CA';
			return $available;
		};
		add_filter( 'get_available_languages', $languages );
		$switcher = new WP_Locale_Switcher();
		remove_filter( 'get_available_languages', $languages );
		$switcher->init();

		try {
			$this->assertTrue( $switcher->switch_to_locale( 'fr_CA' ) );
			$this->assertSame( 'Produits', __( 'Products', 'open-bulk-buying' ) );
			$this->assertSame( 'Votre commande a été annulée', __( 'Your order was cancelled', 'open-bulk-buying' ) );
			$this->assertSame( 'Paiement des frais d’adhésion confirmé', __( 'Membership payment confirmed', 'open-bulk-buying' ) );
			$this->assertSame( '%d article', _n( '%d item', '%d items', 0, 'open-bulk-buying' ) );
			$this->assertSame( '%d article', _n( '%d item', '%d items', 1, 'open-bulk-buying' ) );
			$this->assertSame( '%d articles', _n( '%d item', '%d items', 2, 'open-bulk-buying' ) );
			$this->assertSame( 'fr', openbb_current_language() );

			OPENBB_Frontend::forms_state();
			$state = wp_interactivity_state( 'openbb/forms' );
			$this->assertSame( 'Ce champ est obligatoire.', $state['messages']['required'] );

			$email = OPENBB_Emails::render( 'signup-code', array( 'code' => '123456', 'minutes' => 10 ) );
			$this->assertStringContainsString( 'Votre code d’inscription : 123456', $email['subject'] );
			$this->assertStringContainsString( 'Le code expire dans 10 minutes.', $email['message'] );

			wp_register_script(
				'openbb-translation-test',
				OPENBB_PLUGIN_URL . 'build/admin/index.js',
				array( 'wp-i18n' )
			);
			$loaded = load_script_textdomain( 'openbb-translation-test', 'open-bulk-buying', OPENBB_PLUGIN_DIR . 'languages' );
			$this->assertNotFalse( $loaded );
			$json = json_decode( $loaded, true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame( array( 'Produits' ), $json['locale_data']['messages']['Products'] );
			$this->assertSame( array( 'Rouvrir comme brouillon' ), $json['locale_data']['messages']['Reopen as draft'] );
			$this->assertSame( array( 'Nettoyage de préparation' ), $json['locale_data']['messages']['Preparation cleanup'] );
			$this->assertSame( array( 'Confirmer le paiement déclaré des frais d’adhésion' ), $json['locale_data']['messages']['Confirm claimed membership payment'] );
		} finally {
			wp_deregister_script( 'openbb-translation-test' );
			$switcher->restore_previous_locale();
			remove_filter( 'locale', array( $switcher, 'filter_locale' ) );
			remove_filter( 'determine_locale', array( $switcher, 'filter_locale' ) );
			OPENBB_Frontend::forms_state();
		}

		$this->assertSame( $original, __( 'Products', 'open-bulk-buying' ) );
	}
}
