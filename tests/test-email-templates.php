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
		$url = home_url( '/login/?token=example' );
		$page = home_url( '/login/' );
		$cases = array(
			array(
				'signup-code',
				array( 'code' => '123 456', 'minutes' => 10 ),
				"[$site] Your sign-up code: 123 456",
				"Enter this code to finish joining $site:\n\n123 456\n\nThe code expires in 10 minutes.\n\nIf you didn't ask to join, you can ignore this email.",
			),
			array(
				'account-exists',
				array(),
				"[$site] You already have an account",
				"Someone, hopefully you, tried to sign up to $site with this email address. You already have an account, so there's no code to enter.\n\nLog in to finish joining:\n" . wp_login_url() . "\n\nForgot your password? Reset it here:\n" . wp_lostpassword_url() . "\n\nIf this wasn't you, you can ignore this email.",
			),
			array(
				'welcome',
				array( 'first_name' => 'Jane', 'is_applicant' => true ),
				"[$site] We received your membership application",
				"Hi Jane,\n\nThanks for applying to join $site. A coordinator will review your application. You'll be able to place orders once it's approved." . $this->welcome_footer(),
			),
			array(
				'welcome',
				array( 'first_name' => 'Jane', 'is_applicant' => false ),
				"[$site] Welcome to the co-op",
				"Hi Jane,\n\nWelcome to $site! You can place an order whenever an order cycle is open." . $this->welcome_footer(),
			),
			array(
				'login-link',
				array( 'url' => $url, 'minutes' => 15 ),
				"[$site] Your login link",
				"Use this link to log in to $site:\n\n$url\n\nThe link works once and expires in 15 minutes.\n\nIf you didn't ask to log in, you can ignore this email.",
			),
			array(
				'password-required',
				array( 'page' => $page ),
				"[$site] Log in with your password",
				"Someone asked for a login link for your account on $site.\n\nAccounts that can manage the site can't log in with an emailed link. Please log in with your password instead:\n\n$page\n\nIf you've forgotten your password, you can reset it here:\n\n" . wp_lostpassword_url( $page ) . "\n\nIf you didn't ask to log in, you can ignore this email.",
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
