<?php
/**
 * A published, immutable version of the co-op rules. Datetimes are UTC.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Rules_Version extends OPENBB_Entity {

	public $id                    = 0;
	public $content               = array();
	public $content_hash          = '';
	public $requires_reacceptance = true;
	public $published_by          = 0;
	public $published_at          = null;

	protected function cast(): void {
		$this->id                    = (int) $this->id;
		$this->content               = OPENBB_Rules::normalize_content( self::decode_json( $this->content ) );
		$this->content_hash          = (string) $this->content_hash;
		$this->requires_reacceptance = (bool) $this->requires_reacceptance;
		$this->published_by          = (int) $this->published_by;
	}

	public function get_content( ?string $language = null ): string {
		$items = array_map(
			static function ( array $item ) use ( $language ): string {
				return '<li>' . openbb_localized( $item, $language ) . '</li>';
			},
			$this->content
		);

		return $items ? '<ol>' . implode( '', $items ) . '</ol>' : '';
	}
}
