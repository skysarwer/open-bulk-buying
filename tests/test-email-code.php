<?php
/**
 * Emailed verification codes.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Email_Code extends OPENBB_TestCase {

	private function issue( bool $deliverable = true ): array {
		$issued = OPENBB_Email_Code::issue( OPENBB_Email_Code::PURPOSE_SIGNUP, 'jane@example.com', array( 'answer' => 42 ), $deliverable );
		$this->assertIsArray( $issued );

		return $issued;
	}

	/**
	 * Moves the last send back in time, past the resend cooldown.
	 */
	private function age( string $token, int $seconds ): void {
		$key       = 'openbb_code_' . hash( 'sha256', $token );
		$challenge = get_transient( $key );
		$challenge['sent_at'] -= $seconds;
		set_transient( $key, $challenge, OPENBB_Email_Code::TTL );
	}

	private function wrong( string $code ): string {
		return str_pad( (string) ( ( (int) $code + 1 ) % 1000000 ), OPENBB_Email_Code::LENGTH, '0', STR_PAD_LEFT );
	}

	public function test_issue_returns_a_token_and_a_six_digit_code(): void {
		$issued = $this->issue();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{40}$/', $issued['token'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $issued['code'] );
		$this->assertSame( OPENBB_Email_Code::RESEND_COOLDOWN, $issued['resend_after'] );
		$this->assertSame( '123456', OPENBB_Email_Code::format( '123 456' ) );
	}

	public function test_the_code_is_not_stored_in_plain_text(): void {
		$issued = $this->issue();

		$stored = get_transient( 'openbb_code_' . hash( 'sha256', $issued['token'] ) );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( $issued['code'], wp_json_encode( $stored ) );
	}

	public function test_verify_returns_the_payload_once(): void {
		$issued = $this->issue();

		$verified = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], OPENBB_Email_Code::format( $issued['code'] ) );
		$this->assertSame(
			array(
				'email'   => 'jane@example.com',
				'payload' => array( 'answer' => 42 ),
			),
			$verified
		);

		$again = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $issued['code'] );
		$this->assertWPError( $again );
		$this->assertSame( 'openbb_code_expired', $again->get_error_code() );
	}

	public function test_wrong_codes_count_down_then_lock(): void {
		$issued = $this->issue();
		$wrong  = $this->wrong( $issued['code'] );

		for ( $i = 1; $i < OPENBB_Email_Code::MAX_ATTEMPTS; $i++ ) {
			$result = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $wrong );
			$this->assertSame( 'openbb_code_invalid', $result->get_error_code() );
			$this->assertSame( OPENBB_Email_Code::MAX_ATTEMPTS - $i, $result->get_error_data()['remaining'] );
		}

		$locked = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $wrong );
		$this->assertSame( 'openbb_code_locked', $locked->get_error_code() );

		$right = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $issued['code'] );
		$this->assertSame( 'openbb_code_locked', $right->get_error_code() );
	}

	public function test_undeliverable_challenges_never_verify(): void {
		$issued = $this->issue( false );

		$result = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $issued['code'] );
		$this->assertSame( 'openbb_code_invalid', $result->get_error_code() );
		$this->assertFalse( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] )['deliverable'] );
	}

	public function test_codes_are_scoped_to_their_purpose(): void {
		$issued = $this->issue();

		$this->assertNull( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_LOGIN, $issued['token'] ) );
		$result = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_LOGIN, $issued['token'], $issued['code'] );
		$this->assertSame( 'openbb_code_expired', $result->get_error_code() );
		$this->assertWPError( OPENBB_Email_Code::issue( 'nope', 'jane@example.com' ) );
	}

	public function test_malformed_and_unknown_tokens_are_rejected(): void {
		$this->assertNull( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, '' ) );
		$this->assertNull( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, '../etc' ) );
		$this->assertNull( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, str_repeat( 'a', 40 ) ) );
	}

	public function test_expired_challenges_are_ignored(): void {
		$issued    = $this->issue();
		$key       = 'openbb_code_' . hash( 'sha256', $issued['token'] );
		$challenge = get_transient( $key );
		$challenge['expires_at'] = time() - 1;
		set_transient( $key, $challenge, HOUR_IN_SECONDS );

		$this->assertNull( OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] ) );
		$result = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $issued['code'] );
		$this->assertSame( 'openbb_code_expired', $result->get_error_code() );
	}

	public function test_resend_waits_for_the_cooldown(): void {
		$issued = $this->issue();

		$early = OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] );
		$this->assertSame( 'openbb_code_cooldown', $early->get_error_code() );
		$this->assertGreaterThan( 0, $early->get_error_data()['resend_after'] );
	}

	public function test_resend_replaces_the_code_and_resets_attempts(): void {
		$issued = $this->issue();
		OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $this->wrong( $issued['code'] ) );
		$this->age( $issued['token'], OPENBB_Email_Code::RESEND_COOLDOWN );

		$resent = OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] );
		$this->assertIsArray( $resent );
		$this->assertSame( 'jane@example.com', $resent['email'] );

		if ( $resent['code'] !== $issued['code'] ) {
			$old = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $issued['code'] );
			$this->assertSame( OPENBB_Email_Code::MAX_ATTEMPTS - 1, $old->get_error_data()['remaining'] );
		}
		$this->assertIsArray( OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'], $resent['code'] ) );
	}

	public function test_resends_are_capped(): void {
		$issued = $this->issue();

		for ( $i = 1; $i < OPENBB_Email_Code::MAX_SENDS; $i++ ) {
			$this->age( $issued['token'], OPENBB_Email_Code::RESEND_COOLDOWN );
			$this->assertIsArray( OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] ) );
		}

		$this->age( $issued['token'], OPENBB_Email_Code::RESEND_COOLDOWN );
		$capped = OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_SIGNUP, $issued['token'] );
		$this->assertSame( 'openbb_code_too_many_sends', $capped->get_error_code() );
	}
}
