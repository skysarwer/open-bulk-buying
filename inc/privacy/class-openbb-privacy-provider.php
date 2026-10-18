<?php
/**
 * One area of member data (profile fields, contact details, orders, aid requests) taking
 * part in WordPress's personal data export and erasure tools.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each provider is registered as its own WP exporter and eraser, so WordPress pages
 * through them separately and a large area (orders) can't hold up a small one.
 *
 * Erasure policy is per provider: personal data is deleted or anonymised, but records
 * the co-op must keep (financial amounts, stock history) are retained and reported in
 * the eraser's `items_retained` / `messages`.
 */
abstract class OPENBB_Privacy_Provider {

	/**
	 * Unique id, used for the WP exporter/eraser key and export group id.
	 */
	abstract public function id(): string;

	/**
	 * Name shown in the WP privacy tools and as the export group label.
	 */
	abstract public function label(): string;

	/**
	 * Export items for one page.
	 *
	 * @return array{data: array[], done: bool} As expected by a WP exporter callback.
	 */
	abstract public function export( WP_User $user, int $page ): array;

	/**
	 * Erase or anonymise data for one page.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	abstract public function erase( WP_User $user, int $page ): array;

	/**
	 * An export item in this provider's group.
	 *
	 * @param array[] $data name/value pairs.
	 */
	protected function item( string $item_id, array $data ): array {
		return array(
			'group_id'    => 'openbb-' . $this->id(),
			'group_label' => $this->label(),
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}
}
