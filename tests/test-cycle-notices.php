<?php
/**
 * Cycle notices: storage on the cycle, rules, validation, REST and the order page.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Cycle_Notices extends OPENBB_TestCase {

	/** @var int A coordinator, who can manage cycles and so their notices. */
	private $staff;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->staff = $this->make_user( 'coordinator' );
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	private function notice( int $cycle_id, array $input = array(), bool $publish = true ): array {
		$notice = OPENBB_Cycle_Notices::save(
			$cycle_id,
			null,
			array_merge(
				array( 'content' => array( 'default' => 'Pickup moved to Saturday' ) ),
				$publish ? array( 'status' => OPENBB_Cycle_Notices::STATUS_PUBLISHED ) : array(),
				$input
			),
			0
		);
		$this->assertNotWPError( $notice );

		return $notice;
	}

	private function move_cycle( int $cycle_id, string $status ): OPENBB_Cycle {
		global $wpdb;

		$wpdb->update( $wpdb->openbb_cycles, array( 'status' => $status ), array( 'id' => $cycle_id ) );

		return OPENBB_Cycles::get( $cycle_id );
	}

	private function open_ordering( int $cycle_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->openbb_cycles,
			array(
				'opens_at'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'closes_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			),
			array( 'id' => $cycle_id )
		);
	}

	public function test_a_notice_lives_on_its_cycle_and_starts_as_an_info_draft(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id, array(), false );

		$this->assertMatchesRegularExpression( OPENBB_Cycle_Notices::ID_PATTERN, $notice['id'] );
		$this->assertSame( OPENBB_Cycle_Notices::TYPE_INFO, $notice['type'] );
		$this->assertSame( OPENBB_Cycle_Notices::STATUS_DRAFT, $notice['status'] );
		$this->assertSame( OPENBB_Cycle_Notices::RULE_PUBLISH, $notice['open_rule'] );
		$this->assertSame( OPENBB_Cycle_Notices::RULE_MANUAL, $notice['close_rule'] );
		$this->assertSame( 1, $notice['revision'] );

		$cycle = OPENBB_Cycles::get( $cycle_id );
		$this->assertSame( array( $notice['id'] ), wp_list_pluck( $cycle->notices, 'id' ) );
		$this->assertSame( array(), OPENBB_Cycles::get( $this->make_cycle( 'setup' ) )->notices );
	}

	public function test_saving_or_storing_a_cycle_never_overwrites_its_notices(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id );

		// A cycle object read before the notice existed, like a staff member's open edit form.
		$stale       = OPENBB_Cycles::get( $cycle_id );
		$stale->name = 'Renamed';
		$stale->notices = array();
		$this->assertTrue( $stale->save() );
		$this->assertTrue( OPENBB_Cycles::store( $stale, 'setup', $this->staff ) );

		$cycle = OPENBB_Cycles::get( $cycle_id );
		$this->assertSame( 'Renamed', $cycle->name );
		$this->assertSame( array( $notice['id'] ), wp_list_pluck( $cycle->notices, 'id' ) );
	}

	public function test_only_info_and_alert_exist(): void {
		$cycle_id = $this->make_cycle( 'setup' );

		$alert = $this->notice( $cycle_id, array( 'type' => 'alert' ) );
		$this->assertSame( 'alert', $alert['type'] );
		$this->assertSame( array( 'info', 'alert' ), OPENBB_Cycle_Notices::TYPES );

		foreach ( array( 'error', 'success', 'warning', '' ) as $type ) {
			$result = OPENBB_Cycle_Notices::save( $cycle_id, $alert['id'], array( 'type' => $type ), $alert['revision'] );
			$this->assertSame( 'openbb_invalid_notice', $result->get_error_code(), "type '$type'" );
		}
		$this->assertSame( 'alert', OPENBB_Cycles::get( $cycle_id )->notices[0]['type'] );
	}

	public function test_default_rules_show_a_published_notice_until_it_is_unpublished(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id, array(), false );
		$cycle    = OPENBB_Cycles::get( $cycle_id );

		$this->assertSame( OPENBB_Cycle_Notices::PHASE_DRAFT, OPENBB_Cycle_Notices::phase( $notice, $cycle ) );
		$this->assertSame( array(), OPENBB_Cycle_Notices::open( $cycle ) );

		$published = OPENBB_Cycle_Notices::save( $cycle_id, $notice['id'], array( 'status' => 'published' ), $notice['revision'] );
		$cycle     = OPENBB_Cycles::get( $cycle_id );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_OPEN, OPENBB_Cycle_Notices::phase( $published, $cycle ) );
		$this->assertSame( array( $notice['id'] ), wp_list_pluck( OPENBB_Cycle_Notices::open( $cycle ), 'id' ) );

		// Unlike a poll, a published notice can go back to draft.
		$draft = OPENBB_Cycle_Notices::save( $cycle_id, $notice['id'], array( 'status' => 'draft' ), $published['revision'] );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_DRAFT, OPENBB_Cycle_Notices::phase( $draft, OPENBB_Cycles::get( $cycle_id ) ) );
	}

	public function test_stage_rules_follow_the_cycle_and_reopen_when_it_moves_back(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice(
			$cycle_id,
			array(
				'open_rule'   => 'stage',
				'open_stage'  => 'ordering',
				'close_rule'  => 'stage',
				'close_stage' => 'processing',
			)
		);

		$this->assertSame( OPENBB_Cycle_Notices::PHASE_SCHEDULED, OPENBB_Cycle_Notices::phase( $notice, $this->move_cycle( $cycle_id, 'setup' ) ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_OPEN, OPENBB_Cycle_Notices::phase( $notice, $this->move_cycle( $cycle_id, 'ordering' ) ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_ENDED, OPENBB_Cycle_Notices::phase( $notice, $this->move_cycle( $cycle_id, 'distribution' ) ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_OPEN, OPENBB_Cycle_Notices::phase( $notice, $this->move_cycle( $cycle_id, 'ordering' ) ) );
	}

	public function test_date_rules_open_and_close_at_the_exact_second(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$cycle    = OPENBB_Cycles::get( $cycle_id );
		$opens    = strtotime( '2030-01-02 10:00:00 UTC' );
		$closes   = strtotime( '2030-01-02 12:00:00 UTC' );
		$notice   = $this->notice(
			$cycle_id,
			array(
				'open_rule'  => 'date',
				'open_at'    => '2030-01-02 10:00:00',
				'close_rule' => 'date',
				'close_at'   => '2030-01-02 12:00:00',
			)
		);

		$this->assertSame( OPENBB_Cycle_Notices::PHASE_SCHEDULED, OPENBB_Cycle_Notices::phase( $notice, $cycle, $opens - 1 ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_OPEN, OPENBB_Cycle_Notices::phase( $notice, $cycle, $opens ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_OPEN, OPENBB_Cycle_Notices::phase( $notice, $cycle, $closes - 1 ) );
		$this->assertSame( OPENBB_Cycle_Notices::PHASE_ENDED, OPENBB_Cycle_Notices::phase( $notice, $cycle, $closes ) );
	}

	public function test_rules_that_could_never_show_the_notice_are_rejected(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id, array(), false );
		$bad      = array(
			'stage closing no later than opening'  => array(
				'open_rule'   => 'stage',
				'open_stage'  => 'processing',
				'close_rule'  => 'stage',
				'close_stage' => 'ordering',
			),
			'closing at the first stage'          => array(
				'close_rule'  => 'stage',
				'close_stage' => 'setup',
			),
			'a date rule without a date'          => array( 'open_rule' => 'date' ),
			'a date that is not a date'           => array(
				'close_rule' => 'date',
				'close_at'   => 'soon',
			),
			'closing before opening'              => array(
				'open_rule'  => 'date',
				'open_at'    => '2030-01-02 12:00:00',
				'close_rule' => 'date',
				'close_at'   => '2030-01-02 10:00:00',
			),
			'an unknown rule'                     => array( 'open_rule' => 'whenever' ),
			'manual as an opening rule'           => array( 'open_rule' => 'manual' ),
		);
		foreach ( $bad as $label => $input ) {
			$result = OPENBB_Cycle_Notices::save( $cycle_id, $notice['id'], $input, $notice['revision'] );
			$this->assertSame( 'openbb_invalid_notice', is_wp_error( $result ) ? $result->get_error_code() : '', $label );
		}

		// Unused rule fields don't keep stale values.
		$saved = OPENBB_Cycle_Notices::save( $cycle_id, $notice['id'], array( 'open_rule' => 'publish', 'open_at' => '2030-01-02 10:00:00', 'close_stage' => 'complete' ), $notice['revision'] );
		$this->assertNull( $saved['open_at'] );
		$this->assertSame( 'processing', $saved['close_stage'] );
	}

	public function test_content_and_call_to_action_are_validated(): void {
		$cycle_id = $this->make_cycle( 'setup' );

		$invalid = array(
			'empty text'          => array( 'content' => array( 'default' => '  ' ) ),
			'text that is long'   => array( 'content' => array( 'default' => str_repeat( 'a', OPENBB_Cycle_Notices::CONTENT_MAX + 1 ) ) ),
			'link without text'   => array( 'cta_url' => 'https://example.org' ),
			'text without link'   => array( 'cta_text' => array( 'default' => 'Read more' ) ),
			'script link'         => array(
				'cta_text' => array( 'default' => 'Read more' ),
				'cta_url'  => 'javascript:alert(1)',
			),
			'data link'           => array(
				'cta_text' => array( 'default' => 'Read more' ),
				'cta_url'  => 'data:text/html,hi',
			),
			'ftp link'            => array(
				'cta_text' => array( 'default' => 'Read more' ),
				'cta_url'  => 'ftp://example.org/file',
			),
			'button text too long' => array(
				'cta_text' => array( 'default' => str_repeat( 'a', OPENBB_Cycle_Notices::CTA_TEXT_MAX + 1 ) ),
				'cta_url'  => 'https://example.org',
			),
		);
		foreach ( $invalid as $label => $input ) {
			$result = OPENBB_Cycle_Notices::save( $cycle_id, null, array_merge( array( 'content' => array( 'default' => 'Hello' ) ), $input ), 0 );
			$this->assertSame( 'openbb_invalid_notice', is_wp_error( $result ) ? $result->get_error_code() : '', $label );
		}
		$this->assertSame( array(), OPENBB_Cycles::get( $cycle_id )->notices );

		foreach ( array( 'https://example.org/join?a=1&b=2', 'mailto:orders@example.org', 'tel:+15555550100', '/order/', '#openbb-order' ) as $url ) {
			$notice = $this->notice(
				$cycle_id,
				array(
					'cta_text' => array( 'default' => 'Read more' ),
					'cta_url'  => $url,
				)
			);
			$this->assertSame( $url, $notice['cta_url'], $url );
		}
		$this->assertSame( 'http://example.org', $this->notice( $cycle_id, array( 'cta_text' => array( 'default' => 'Go' ), 'cta_url' => ' example.org ' ) )['cta_url'] );

		$plain = $this->notice( $cycle_id, array( 'content' => array( 'default' => "<b>Bold</b> & <script>x</script>\nSecond line", 'fr' => '' ) ) );
		$this->assertSame( array( 'default' ), array_keys( $plain['content'] ) );
		$this->assertStringNotContainsString( '<', $plain['content']['default'] );
		$this->assertStringContainsString( "\nSecond line", $plain['content']['default'] );
		$this->assertSame( array( 'default' => '' ), $plain['cta_text'] );
	}

	public function test_a_cycle_holds_a_limited_number_of_notices(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		for ( $i = 0; $i < OPENBB_Cycle_Notices::MAX_PER_CYCLE; $i++ ) {
			$this->notice( $cycle_id, array(), false );
		}

		$over = OPENBB_Cycle_Notices::save( $cycle_id, null, array( 'content' => array( 'default' => 'One too many' ) ), 0 );
		$this->assertSame( 'openbb_invalid_notice', $over->get_error_code() );
		$this->assertCount( OPENBB_Cycle_Notices::MAX_PER_CYCLE, OPENBB_Cycles::get( $cycle_id )->notices );
	}

	public function test_a_stale_revision_is_a_conflict_and_other_notices_are_unaffected(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$first    = $this->notice( $cycle_id, array(), false );
		$other    = $this->notice( $cycle_id, array( 'content' => array( 'default' => 'Other' ) ), false );

		$saved = OPENBB_Cycle_Notices::save( $cycle_id, $first['id'], array( 'content' => array( 'default' => 'First' ) ), $first['revision'] );
		$stale = OPENBB_Cycle_Notices::save( $cycle_id, $first['id'], array( 'content' => array( 'default' => 'Second' ) ), $first['revision'] );
		// A different notice keeps its own revision.
		$kept = OPENBB_Cycle_Notices::save( $cycle_id, $other['id'], array( 'type' => 'alert' ), $other['revision'] );

		$this->assertSame( 2, $saved['revision'] );
		$this->assertSame( 'openbb_notice_stale', $stale->get_error_code() );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertNotWPError( $kept );

		$notices = array_column( OPENBB_Cycles::get( $cycle_id )->notices, null, 'id' );
		$this->assertSame( 'First', $notices[ $first['id'] ]['content']['default'] );
		$this->assertSame( 'alert', $notices[ $other['id'] ]['type'] );
	}

	public function test_a_write_that_loses_a_race_is_retried_on_the_latest_notices(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'setup' );
		$racer    = wp_json_encode(
			array(
				array(
					'id'       => 'racernotice',
					'content'  => array( 'default' => 'Written by someone else' ),
					'revision' => 1,
				),
			)
		);
		$race     = function ( $query ) use ( &$race, $wpdb, $racer, $cycle_id ) {
			if ( 0 === strpos( (string) $query, "UPDATE {$wpdb->openbb_cycles} SET notices" ) ) {
				remove_filter( 'query', $race );
				$wpdb->update( $wpdb->openbb_cycles, array( 'notices' => $racer ), array( 'id' => $cycle_id ) );
			}

			return $query;
		};
		add_filter( 'query', $race );

		$mine = $this->notice( $cycle_id, array( 'content' => array( 'default' => 'Mine' ) ), false );
		remove_filter( 'query', $race );

		$texts = wp_list_pluck( wp_list_pluck( OPENBB_Cycles::get( $cycle_id )->notices, 'content' ), 'default' );
		$this->assertSame( array( 'Written by someone else', 'Mine' ), $texts );
		$this->assertSame( 'Mine', $mine['content']['default'] );
	}

	public function test_a_change_that_differs_only_in_letter_case_is_still_a_conflict(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id, array( 'content' => array( 'default' => 'lower case' ) ), false );
		$race     = function ( $query ) use ( &$race, $wpdb, $cycle_id ) {
			if ( 0 === strpos( (string) $query, "UPDATE {$wpdb->openbb_cycles} SET notices" ) ) {
				remove_filter( 'query', $race );
				$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notices FROM {$wpdb->openbb_cycles} WHERE id = %d", $cycle_id ) );
				$wpdb->update( $wpdb->openbb_cycles, array( 'notices' => str_replace( 'lower case', 'LOWER CASE', $stored ) ), array( 'id' => $cycle_id ) );
			}

			return $query;
		};
		add_filter( 'query', $race );
		$saved = OPENBB_Cycle_Notices::save( $cycle_id, $notice['id'], array( 'type' => 'alert' ), $notice['revision'] );
		remove_filter( 'query', $race );

		// The retry re-reads the other writer's text instead of overwriting it.
		$this->assertNotWPError( $saved );
		$current = OPENBB_Cycles::get( $cycle_id )->notices[0];
		$this->assertSame( 'LOWER CASE', $current['content']['default'] );
		$this->assertSame( 'alert', $current['type'] );
	}

	public function test_a_notice_can_be_deleted_and_a_missing_one_is_not_found(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$keep     = $this->notice( $cycle_id, array(), false );
		$drop     = $this->notice( $cycle_id, array(), false );

		$this->assertTrue( OPENBB_Cycle_Notices::delete( $cycle_id, $drop['id'] ) );
		$this->assertSame( array( $keep['id'] ), wp_list_pluck( OPENBB_Cycles::get( $cycle_id )->notices, 'id' ) );
		$this->assertSame( 'openbb_notice_not_found', OPENBB_Cycle_Notices::delete( $cycle_id, $drop['id'] )->get_error_code() );
		$this->assertSame( 'openbb_notice_not_found', OPENBB_Cycle_Notices::save( $cycle_id, $drop['id'], array(), 1 )->get_error_code() );
		$this->assertSame( 'openbb_cycle_not_found', OPENBB_Cycle_Notices::delete( 0, $keep['id'] )->get_error_code() );
	}

	public function test_stored_notices_are_read_leniently(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'setup' );
		$wpdb->update(
			$wpdb->openbb_cycles,
			array(
				'notices' => wp_json_encode(
					array(
						array(
							'id'       => 'oddnotice1',
							'type'     => 'success',
							'content'  => 'Plain text',
							'status'   => 'live',
							'revision' => 0,
						),
						array( 'content' => array( 'default' => 'No id' ) ),
						'not an object',
					)
				),
			),
			array( 'id' => $cycle_id )
		);

		$notices = OPENBB_Cycles::get( $cycle_id )->notices;
		$this->assertCount( 1, $notices );
		$this->assertSame( 'info', $notices[0]['type'] );
		$this->assertSame( 'draft', $notices[0]['status'] );
		$this->assertSame( array( 'default' => 'Plain text' ), $notices[0]['content'] );
		$this->assertSame( 1, $notices[0]['revision'] );

		$wpdb->update( $wpdb->openbb_cycles, array( 'notices' => '{broken' ), array( 'id' => $cycle_id ) );
		$this->assertSame( array(), OPENBB_Cycles::get( $cycle_id )->notices );
	}

	public function test_notice_text_and_button_follow_the_language_with_a_default_fallback(): void {
		$notice = $this->notice(
			$this->make_cycle( 'setup' ),
			array(
				'content'  => array( 'default' => 'Pickup is on Saturday', 'fr' => 'Le ramassage est samedi' ),
				'cta_text' => array( 'default' => 'See the map' ),
				'cta_url'  => 'https://example.org/map',
			)
		);

		$this->assertSame( 'Le ramassage est samedi', OPENBB_Cycle_Notices::content( $notice, 'fr' ) );
		$this->assertSame( 'Pickup is on Saturday', OPENBB_Cycle_Notices::content( $notice, 'de' ) );
		$this->assertSame( 'See the map', OPENBB_Cycle_Notices::cta( $notice, 'fr' )['text'] );
		$this->assertNull( OPENBB_Cycle_Notices::cta( $this->notice( $this->make_cycle( 'setup' ) ) ) );
	}

	public function test_staff_routes_need_cycle_management(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$notice   = $this->notice( $cycle_id );
		$base     = '/cycles/' . $cycle_id . '/notices';

		$routes = array(
			array( 'GET', $base, array() ),
			array( 'POST', $base, array( 'content' => array( 'default' => 'Hi' ) ) ),
			array( 'GET', $base . '/' . $notice['id'], array() ),
			array( 'PUT', $base . '/' . $notice['id'], array( 'revision' => $notice['revision'], 'type' => 'alert' ) ),
			array( 'DELETE', $base . '/' . $notice['id'], array() ),
		);
		foreach ( array( 'member' => 403, 'aid_reviewer' => 403, '' => 403 ) as $membership => $status ) {
			wp_set_current_user( $this->make_user( $membership ) );
			foreach ( $routes as list( $method, $route, $params ) ) {
				$this->assertSame( $status, $this->api( $method, $route, $params )->get_status(), "$method $route as '$membership'" );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->api( 'GET', $base )->get_status() );

		$this->assertSame( array( $notice['id'] ), wp_list_pluck( OPENBB_Cycles::get( $cycle_id )->notices, 'id' ) );
		$this->assertSame( 'info', OPENBB_Cycles::get( $cycle_id )->notices[0]['type'] );
	}

	public function test_staff_manage_notices_through_rest(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$base     = '/cycles/' . $cycle_id . '/notices';
		wp_set_current_user( $this->staff );

		$created = $this->api(
			'POST',
			$base,
			array(
				'type'       => 'alert',
				'content'    => array( 'default' => ' <b>Heads up</b> ', 'fr' => 'Attention' ),
				'close_rule' => 'date',
				'close_at'   => '2030-01-02T10:00:00+00:00',
				'cta_text'   => array( 'default' => 'Details' ),
				'cta_url'    => 'https://example.org/details',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$notice = $created->get_data();
		$this->assertSame( array( 'default' => 'Heads up', 'fr' => 'Attention' ), $notice['content'] );
		$this->assertSame( 'alert', $notice['type'] );
		$this->assertSame( 'draft', $notice['phase'] );
		$this->assertSame( 'Draft', $notice['phase_label'] );
		$this->assertSame( strtotime( '2030-01-02 10:00:00 UTC' ), strtotime( $notice['close_at'] ) );
		$this->assertSame( $cycle_id, $notice['cycle_id'] );

		$route     = $base . '/' . $notice['id'];
		$published = $this->api( 'PUT', $route, array( 'status' => 'published', 'revision' => $notice['revision'] ) );
		$this->assertSame( 200, $published->get_status() );
		$this->assertSame( 'open', $published->get_data()['phase'] );
		$this->assertSame( 2, $published->get_data()['revision'] );
		$this->assertSame( 409, $this->api( 'PUT', $route, array( 'status' => 'draft', 'revision' => $notice['revision'] ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', $route, array( 'type' => 'success', 'revision' => 2 ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', $route, array( 'cta_url' => 'javascript:alert(1)', 'revision' => 2 ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', $route, array( 'open_at' => 'tomorrow-ish', 'revision' => 2 ) )->get_status() );

		$this->assertSame( array( $notice['id'] ), wp_list_pluck( $this->api( 'GET', $base )->get_data(), 'id' ) );
		$this->assertSame( 'Details', $this->api( 'GET', $route )->get_data()['cta_text']['default'] );

		$this->assertSame( 404, $this->api( 'GET', '/cycles/999999/notices' )->get_status() );
		$this->assertSame( 404, $this->api( 'POST', '/cycles/999999/notices', array( 'content' => array( 'default' => 'Hi' ) ) )->get_status() );
		$this->assertSame( 404, $this->api( 'GET', $base . '/missing1234' )->get_status() );

		$this->assertTrue( $this->api( 'DELETE', $route )->get_data()['deleted'] );
		$this->assertSame( 404, $this->api( 'GET', $route )->get_status() );
		$this->assertSame( array(), $this->api( 'GET', $base )->get_data() );
	}

	public function test_deleting_a_cycle_deletes_its_notices(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'setup' );
		$this->notice( $cycle_id );

		$this->assertTrue( OPENBB_Cycles::get( $cycle_id )->delete() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->openbb_cycles} WHERE id = %d", $cycle_id ) ) );
	}

	public function test_the_order_page_shows_open_notices_as_info_or_alert_with_their_button(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$this->open_ordering( $cycle_id );
		$this->notice(
			$cycle_id,
			array(
				'content'  => array( 'default' => "Pickup moved & shifted\nto Saturday" ),
				'cta_text' => array( 'default' => 'See the new time' ),
				'cta_url'  => 'https://example.org/pickup?a=1&b=2',
			)
		);
		$this->notice( $cycle_id, array( 'type' => 'alert', 'content' => array( 'default' => 'Supplier delay' ) ) );
		$this->notice( $cycle_id, array( 'content' => array( 'default' => 'Still a draft' ) ), false );
		$this->notice( $cycle_id, array( 'content' => array( 'default' => 'Opens when processing starts' ), 'open_rule' => 'stage', 'open_stage' => 'processing' ) );
		wp_set_current_user( $this->make_user( 'member' ) );

		$form = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'openbb-catalog', $form );
		$this->assertStringContainsString( 'openbb-notice openbb-notice--info openbb-cycle-notice', $form );
		$this->assertStringContainsString( 'openbb-notice openbb-notice--alert openbb-cycle-notice', $form );
		$this->assertStringContainsString( 'Pickup moved &amp; shifted<br />', $form );
		$this->assertStringContainsString( 'Supplier delay', $form );
		$this->assertStringContainsString( 'href="https://example.org/pickup?a=1&#038;b=2">See the new time</a>', $form );
		$this->assertStringNotContainsString( 'Still a draft', $form );
		$this->assertStringNotContainsString( 'Opens when processing starts', $form );
		// With one cycle in view the notice doesn't name it.
		$this->assertStringNotContainsString( 'openbb-cycle-notice__cycle', $form );

		$this->move_cycle( $cycle_id, 'ordering' );
		OPENBB_Cycle_Notices::save( $cycle_id, OPENBB_Cycles::get( $cycle_id )->notices[0]['id'], array( 'status' => 'draft' ), 1 );
		$this->assertStringNotContainsString( 'Pickup moved', OPENBB_Order_Form_View::render() );
	}

	public function test_a_notice_stops_showing_when_its_rule_ends_it(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$this->open_ordering( $cycle_id );
		$this->notice(
			$cycle_id,
			array(
				'content'    => array( 'default' => 'Order early' ),
				'close_rule' => 'date',
				'close_at'   => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			)
		);
		wp_set_current_user( $this->make_user( 'member' ) );

		$this->assertStringNotContainsString( 'Order early', OPENBB_Order_Form_View::render() );
	}

	public function test_notices_show_on_a_cancelled_orders_page_and_while_ordering_is_closed_with_their_cycle(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'ordering' );
		$this->open_ordering( $cycle_id );
		$this->notice( $cycle_id, array( 'content' => array( 'default' => 'Pickup moved' ) ) );
		$member   = $this->make_user( 'member' );
		$order_id = $this->make_order( $cycle_id, $member );
		$wpdb->update( $wpdb->openbb_orders, array( 'status' => OPENBB_Order::STATUS_CANCELLED ), array( 'id' => $order_id ) );
		wp_set_current_user( $member );

		$cancelled = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'Your order was cancelled', $cancelled );
		$this->assertStringContainsString( 'Pickup moved', $cancelled );

		$wpdb->update( $wpdb->openbb_cycles, array( 'status' => 'setup', 'name' => 'Spring cycle', 'opens_at' => null, 'closes_at' => null ), array( 'id' => $cycle_id ) );
		$later = $this->make_cycle( 'processing' );
		$this->notice( $later, array( 'content' => array( 'default' => 'Collect your order' ), 'type' => 'alert' ) );
		$done = $this->make_cycle( 'complete' );
		$this->notice( $done, array( 'content' => array( 'default' => 'From a finished cycle' ) ) );

		$closed = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'no ordering period open', $closed );
		$this->assertStringContainsString( 'Pickup moved', $closed );
		$this->assertStringContainsString( 'Collect your order', $closed );
		$this->assertStringContainsString( 'Spring cycle', $closed );
		$this->assertStringNotContainsString( 'From a finished cycle', $closed );
	}

	public function test_applicants_and_visitors_do_not_see_notices(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$this->open_ordering( $cycle_id );
		$this->notice( $cycle_id, array( 'content' => array( 'default' => 'Members only news' ) ) );

		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Members only news', OPENBB_Order_Form_View::render() );
		wp_set_current_user( $this->make_user( 'applicant' ) );
		$this->assertStringNotContainsString( 'Members only news', OPENBB_Order_Form_View::render() );
	}
}
