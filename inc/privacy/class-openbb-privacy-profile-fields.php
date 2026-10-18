<?php
/**
 * Profile field values (user meta `{blog prefix}openbb_field_{key}`) in the WP privacy tools.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export: every field with a value, including archived and staff-only fields (they are
 * the member's personal data too), labelled in the member's language.
 *
 * Erase: deletes every profile field value of the member on this site, including values
 * of fields no longer in the schema. Nothing is retained.
 */
class OPENBB_Privacy_Profile_Fields extends OPENBB_Privacy_Provider {

	public function id(): string {
		return 'profile-fields';
	}

	public function label(): string {
		return __( 'Co-op profile fields', 'open-bulk-buying' );
	}

	public function export( WP_User $user, int $page ): array {
		$language = (string) openbb_get_member_field( $user->ID, 'language' );
		$language = '' !== $language ? $language : null;

		$values = OPENBB_Profile_Fields::get_values( $user->ID, OPENBB_Profile_Fields::CONTEXT_STAFF );
		$data   = array();
		foreach ( OPENBB_Profile_Fields::get_fields_for_context( OPENBB_Profile_Fields::CONTEXT_STAFF ) as $key => $field ) {
			$value = $this->value_text( $field, $values[ $key ] ?? null, $language );
			if ( '' === $value ) {
				continue;
			}
			$data[] = array(
				'name'  => openbb_localized( $field['label'], $language ),
				'value' => $value,
			);
		}

		return array(
			'data' => $data ? array( $this->item( $this->id() . '-' . $user->ID, $data ) ) : array(),
			'done' => true,
		);
	}

	public function erase( WP_User $user, int $page ): array {
		$removed = 0;
		foreach ( self::stored_meta_keys( $user->ID ) as $meta_key ) {
			if ( delete_user_meta( $user->ID, $meta_key ) ) {
				++$removed;
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Profile field meta keys the user has values for on this site, whether or not the
	 * field is still defined.
	 *
	 * @return string[]
	 */
	public static function stored_meta_keys( int $user_id ): array {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( OPENBB_Profile_Fields::meta_key( '' ) ) . '%'
			)
		);
	}

	/**
	 * @param mixed $value As returned by OPENBB_Profile_Fields::get_values().
	 */
	private function value_text( array $field, $value, ?string $language ): string {
		switch ( $field['type'] ) {
			case OPENBB_Profile_Fields::TYPE_BOOLEAN:
				return $value ? __( 'Yes', 'open-bulk-buying' ) : '';

			case OPENBB_Profile_Fields::TYPE_OPTIONS:
				$labels = array();
				foreach ( $field['options']['groups'] ?? array() as $group ) {
					foreach ( $group['options'] as $option ) {
						$labels[ $option['key'] ] = openbb_localized( $option['label'], $language );
					}
				}

				return implode(
					'; ',
					array_map(
						static function ( string $key ) use ( $labels ): string {
							return $labels[ $key ] ?? $key;
						},
						(array) $value
					)
				);

			default:
				return (string) $value;
		}
	}
}
