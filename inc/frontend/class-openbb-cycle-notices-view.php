<?php
/**
 * Cycle notices for members: the open notices staff attached to a cycle, shown on the order
 * page above the catalog, on a cancelled order's page, and, with each cycle's name, when
 * ordering is closed. Plain markup, no script.
 *
 * Template: cycle-notices. Styles: src/sass/frontend/_cycle-notices.scss.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Cycle_Notices_View {

	/**
	 * The notices open for one cycle, or '' when there are none.
	 */
	public static function for_cycle( OPENBB_Cycle $cycle ): string {
		return self::render(
			array_map(
				static function ( array $notice ) use ( $cycle ): array {
					return array(
						'notice' => $notice,
						'cycle'  => $cycle,
					);
				},
				OPENBB_Cycle_Notices::open( $cycle )
			),
			false
		);
	}

	/**
	 * The notices open in every cycle that isn't complete, each naming its cycle, or ''.
	 */
	public static function for_active_cycles(): string {
		return self::render( OPENBB_Cycle_Notices::open_in_active_cycles(), true );
	}

	/**
	 * @param array[] $entries    Each array( 'notice' => array, 'cycle' => OPENBB_Cycle ).
	 * @param bool    $name_cycle Whether each notice names its cycle.
	 */
	private static function render( array $entries, bool $name_cycle ): string {
		if ( ! $entries ) {
			return '';
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$items[] = array(
				'type'    => $entry['notice']['type'],
				'content' => OPENBB_Cycle_Notices::content( $entry['notice'] ),
				'cta'     => OPENBB_Cycle_Notices::cta( $entry['notice'] ),
				'cycle'   => $name_cycle ? $entry['cycle']->name : '',
			);
		}

		return OPENBB_Frontend::template( 'cycle-notices', array( 'items' => $items ) );
	}
}
