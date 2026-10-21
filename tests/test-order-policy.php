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
		$committed = in_array( $status, array( 'submitted', 'prepaid' ), true ) && 'complete' !== $stage;

		$this->assertSame( $editable, OPENBB_Order_Policy::can_edit_contents( $order, $cycle ) );
		$this->assertSame( $editable, OPENBB_Order_Policy::can_cancel( $order, $cycle ) );
		$this->assertSame( 'draft' === $status, OPENBB_Order_Policy::can_delete( $order ) );
		$this->assertSame( 'submitted' === $status && 'complete' !== $stage, OPENBB_Order_Policy::can_confirm( $order, $cycle, 'prepay' ) );
		$this->assertSame( $committed && 'distribution' === $stage, OPENBB_Order_Policy::can_confirm( $order, $cycle, 'fulfill' ) );
		$this->assertTrue( OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'staff_note' => 'Note' ) ) );

		foreach ( OPENBB_Order_Policy::EDITABLE_STATUSES as $target ) {
			$this->assertSame(
				in_array( $target, OPENBB_Order_Policy::editable_statuses( $order, $cycle ), true ),
				true === OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'status' => $target ) )
			);
			$this->assertSame( 'draft' !== $status || 'cancelled' === $target, OPENBB_Order_Policy::needs_reason( $order, $target ) );
		}
		$structural = OPENBB_Order_Policy::validate_edit( $order, $cycle, array( 'donation' => 1 ) );
		$content = OPENBB_Order_Policy::validate_content_change( $order, false, 1 );
		$this->assertSame( $editable, true === $structural && true === $content );
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
