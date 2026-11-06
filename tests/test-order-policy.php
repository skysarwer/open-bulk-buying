<?php
/**
 * Policy decisions shared by services and the staff UI.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Policy extends OPENBB_TestCase {

	public static function states(): array {
		$cases = array();
		foreach ( array( 'draft', 'submitted', 'prepaid', 'fulfilled', 'cancelled' ) as $status ) {
			foreach ( array( 'setup', 'ordering', 'processing', 'distribution', 'complete' ) as $stage ) {
				$cases[ $status . '-' . $stage ] = array( $status, $stage );
			}
		}
		return $cases;
	}

	/** @dataProvider states */
	public function test_status_and_stage_matrix( string $status, string $stage ): void {
		$order = new OPENBB_Order( array( 'id' => 1, 'status' => $status ) );
		$cycle = new OPENBB_Cycle( array( 'status' => $stage ) );
		$editable = in_array( $status, array( 'draft', 'submitted' ), true ) && 'complete' !== $stage;
		$committed = in_array( $status, array( 'submitted', 'prepaid' ), true );

		$this->assertSame( $editable, OPENBB_Order_Policy::can_edit_contents( $order, $cycle ) );
		$this->assertSame( $editable, OPENBB_Order_Policy::can_cancel( $order, $cycle ) );
		$this->assertSame( 'draft' === $status, OPENBB_Order_Policy::can_delete( $order ) );
		// Payment and handoff can be recorded at any cycle stage.
		$this->assertSame( 'submitted' === $status, OPENBB_Order_Policy::can_confirm( $order, $cycle, 'prepay' ) );
		$this->assertSame( $committed, OPENBB_Order_Policy::can_confirm( $order, $cycle, 'fulfill' ) );
		$this->assertTrue( OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'staff_note' => 'Note' ) ) );

		foreach ( OPENBB_Order_Policy::EDITABLE_STATUSES as $target ) {
			$this->assertSame(
				in_array( $target, OPENBB_Order_Policy::editable_statuses( $order, $cycle ), true ),
				true === OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'status' => $target ) )
			);
			$this->assertSame( 'distribution' !== $stage && ( 'draft' !== $status || 'cancelled' === $target ), OPENBB_Order_Policy::needs_reason( $order, $target, $cycle ) );
			$this->assertSame( 'draft' !== $status || 'cancelled' === $target, OPENBB_Order_Policy::needs_reason( $order, $target ) );
		}
		$structural = OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'donation' => 1 ) );
		$content = OPENBB_Order_Policy::validate_content_change( $order, false, 1 );
		$this->assertSame( $editable, true === $structural && true === $content );
	}

	public function test_reason_is_not_needed_at_pickup_by_stage_or_by_dates(): void {
		$order = new OPENBB_Order( array( 'id' => 1, 'status' => 'submitted' ) );
		$now   = time();
		$cycle = new OPENBB_Cycle(
			array(
				'status'           => 'processing',
				'pickup_opens_at'  => gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ),
				'pickup_closes_at' => gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS ),
			)
		);
		$this->assertTrue( $cycle->in_pickup_window() );
		$this->assertFalse( OPENBB_Order_Policy::needs_reason( $order, 'submitted', $cycle ) );
		$this->assertFalse( OPENBB_Order_Policy::needs_reason( $order, 'cancelled', $cycle ) );

		$this->assertFalse( $cycle->in_pickup_window( $now + 2 * HOUR_IN_SECONDS ) );
		$this->assertFalse( $cycle->in_pickup_window( $now - 2 * HOUR_IN_SECONDS ) );
		$cycle->pickup_opens_at = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
		$cycle->pickup_closes_at = gmdate( 'Y-m-d H:i:s', $now + 2 * HOUR_IN_SECONDS );
		$this->assertTrue( OPENBB_Order_Policy::needs_reason( $order, 'submitted', $cycle ) );
		$cycle->pickup_opens_at = null;
		$this->assertFalse( $cycle->in_pickup_window() );
	}

	public function test_due_stage_follows_the_dates_without_changing_the_stage(): void {
		$now   = time();
		$past  = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$later = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
		$cases = array(
			array( 'setup', 'opens_at', 'ordering' ),
			array( 'ordering', 'closes_at', 'processing' ),
			array( 'processing', 'pickup_opens_at', 'distribution' ),
			array( 'distribution', 'pickup_closes_at', 'complete' ),
		);
		foreach ( $cases as list( $stage, $field, $due ) ) {
			$cycle = new OPENBB_Cycle( array( 'status' => $stage ) );
			$this->assertSame( '', $cycle->due_stage(), "$stage without $field" );
			$cycle->$field = $later;
			$this->assertSame( '', $cycle->due_stage(), "$stage before $field" );
			$cycle->$field = $past;
			$this->assertSame( $due, $cycle->due_stage(), "$stage after $field" );
			$this->assertSame( $stage, $cycle->status );
		}
		$complete = new OPENBB_Cycle( array( 'status' => 'complete', 'pickup_closes_at' => $past ) );
		$this->assertSame( '', $complete->due_stage() );
	}

	public function test_collection_and_operational_history_override_draft_eligibility(): void {
		$cycle = new OPENBB_Cycle( array( 'status' => 'distribution' ) );
		foreach ( array( 'submitted_at', 'paid_at', 'collected_at' ) as $field ) {
			$order = new OPENBB_Order( array( 'id' => 1, $field => '2026-01-01 00:00:00' ) );
			$this->assertFalse( OPENBB_Order_Policy::can_delete( $order ) );
		}
		$collected = new OPENBB_Order( array( 'id' => 1, 'status' => 'submitted', 'collected_at' => '2026-01-01 00:00:00' ) );
		$this->assertFalse( OPENBB_Order_Policy::can_edit_contents( $collected, $cycle ) );
		$this->assertFalse( OPENBB_Order_Policy::can_cancel( $collected, $cycle ) );
		$this->assertFalse( OPENBB_Order_Policy::can_confirm( $collected, $cycle, 'fulfill' ) );
		$this->assertFalse( OPENBB_Order_Policy::can_edit_contents( $collected, null ) );
		$this->assertWPError( OPENBB_Order_Policy::validate_confirmation( $collected, null, 'fulfill' ) );
	}
}
