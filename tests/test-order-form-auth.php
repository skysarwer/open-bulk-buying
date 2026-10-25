<?php
/**
 * The order form for logged-out visitors: login or signup first (auth param), no
 * catalog in the page, and both reload back to the form afterwards.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Form_Auth extends OPENBB_TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		$this->general( array( 'allow_signups' => true ) );
	}

	public function tear_down(): void {
		delete_option( OPENBB_Settings::GENERAL_OPTION );
		parent::tear_down();
	}

	private function general( array $values ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), $values ), false );
	}

	/**
	 * Which auth panels are in the markup, and whether each is shown.
	 *
	 * @return array<string,bool>
	 */
	private function panels( string $html ): array {
		$panels = array();
		$tags   = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'class_name' => 'openbb-auth__panel' ) ) ) {
			$panels[ $tags->get_attribute( 'data-panel' ) ] = null === $tags->get_attribute( 'hidden' );
		}

		return $panels;
	}

	/**
	 * The data-wp-context of the first element in the given store's region.
	 */
	private function context( string $html, string $namespace ): array {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $namespace === $tags->get_attribute( 'data-wp-interactive' ) ) {
				return (array) json_decode( (string) $tags->get_attribute( 'data-wp-context' ), true );
			}
		}
		$this->fail( "No {$namespace} region." );
	}

	public function test_logged_out_visitors_log_in_first_by_default(): void {
		$html = OPENBB_Order_Form_View::render( OPENBB_Order_Form_View::defaults() );

		$this->assertSame( array( 'login' => true, 'signup' => false ), $this->panels( $html ) );
		$this->assertStringContainsString( 'Log in or sign up to place your order.', $html );
		$this->assertStringNotContainsString( 'openbb-order__topbar', $html, 'The catalog is not public.' );
	}

	public function test_the_auth_param_can_open_on_signup(): void {
		$html = OPENBB_Order_Form_View::render( array( 'auth' => 'signup' ) );
		$this->assertSame( array( 'login' => false, 'signup' => true ), $this->panels( $html ) );

		$html = OPENBB_Order_Form_View::render( array( 'auth' => 'nonsense' ) );
		$this->assertSame( array( 'login' => true, 'signup' => false ), $this->panels( $html ) );
	}

	public function test_login_and_signup_reload_back_to_the_form(): void {
		$html   = OPENBB_Order_Form_View::render( array( 'auth' => 'signup' ) );
		$target = OPENBB_Frontend::current_url() . '#' . OPENBB_Order_Form_View::ID;

		$this->assertSame( $target, $this->context( $html, 'openbb/login' )['redirect'] );
		$this->assertSame( $target, $this->context( $html, 'openbb/signup' )['continueTo'] );
		$this->assertStringContainsString( 'id="' . OPENBB_Order_Form_View::ID . '"', $html );
	}

	public function test_the_signup_shortcode_still_shows_its_done_step(): void {
		$html = OPENBB_Signup_View::render();

		$this->assertSame( '', $this->context( $html, 'openbb/signup' )['continueTo'] );
	}

	public function test_only_login_while_signups_are_closed(): void {
		$this->general( array( 'allow_signups' => false ) );

		$html = OPENBB_Order_Form_View::render( array( 'auth' => 'signup' ) );

		$this->assertSame( array( 'login' => true ), $this->panels( $html ) );
		$this->assertStringContainsString( 'Log in to place your order.', $html );
	}
}
