<?php
/**
 * Email template output stays unchanged when rendered outside the sending services.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Email_Templates extends OPENBB_TestCase {

	public function test_all_template_subjects_and_bodies(): void {
		update_option( 'blogname', 'Food &amp; Friends' );
		$site = 'Food & Friends';
		$page = home_url( '/login/' );
		$cases = array(
			array(
				'signup-code',
				array( 'code' => '123456', 'minutes' => 10 ),
				"[$site] Your sign-up code: 123456",
				'<p>Enter this code to finish joining Food &amp; Friends:</p><p style="font-family: monospace; font-size: 2em; font-weight: bold; letter-spacing: 0.1em;">123456</p><p>The code expires in 10 minutes.</p><p>If you didn\'t ask to join, you can ignore this email.</p>',
			),
			array(
				'account-exists',
				array(),
				"[$site] You already have an account",
				"Someone, hopefully you, tried to sign up to $site with this email address. You already have an account, so there's no code to enter.\n\nLog in to finish joining:\n" . wp_login_url() . "\n\nForgot your password? Reset it here:\n" . wp_lostpassword_url() . "\n\nIf this wasn't you, you can ignore this email.",
			),
			array(
				'application-pending',
				array( 'first_name' => 'Jane' ),
				"[$site] We received your membership application",
				"Hi Jane,\n\nThanks for applying to join $site. A coordinator will review your application. You'll be able to place orders once it's approved." . $this->welcome_footer(),
			),
			array(
				'welcome',
				array( 'first_name' => 'Jane' ),
				"[$site] Welcome to the co-op",
				"Hi Jane,\n\nWelcome to $site! You can place an order whenever an order cycle is open." . $this->welcome_footer(),
			),
			array(
				'login-code',
				array( 'code' => '123456', 'minutes' => 15 ),
				"[$site] Your login code: 123456",
				'<p>Enter this code in the browser where you asked to log in to Food &amp; Friends:</p><p style="font-family: monospace; font-size: 2em; font-weight: bold; letter-spacing: 0.1em;">123456</p><p>The code works once and expires in 15 minutes. Don\'t share it with anyone.</p><p>If you didn\'t ask to log in, you can ignore this email.</p>',
			),
			array(
				'password-required',
				array( 'page' => $page ),
				"[$site] Log in with your password",
				"Someone asked for a login code for your account on $site.\n\nEmail-code login isn't available for this account. Please log in with your password instead:\n\n$page\n\nIf you've forgotten your password, you can reset it here:\n\n" . wp_lostpassword_url( $page ) . "\n\nIf you didn't ask to log in, you can ignore this email.",
			),
		);

		foreach ( $cases as list( $name, $data, $subject, $message ) ) {
			$this->assertSame(
				array( 'subject' => $subject, 'message' => $message ),
				OPENBB_Emails::render( $name, $data ),
				$name
			);
		}
	}

	private function welcome_footer(): string {
		return "\n\nLog in at any time:\n" . wp_login_url() . "\n\nTo choose a password, use \"Lost your password?\":\n" . wp_lostpassword_url();
	}

	public function test_unknown_templates_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		OPENBB_Emails::render( '../model/member/user-meta' );
	}
}
