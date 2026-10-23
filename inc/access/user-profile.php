<?php
/**
 * Co-op membership column on the core Users list. Statuses and roles are managed on the
 * co-op Members screen.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function openbb_user_access_column( array $columns ): array {
	$columns['openbb_membership'] = __( 'Co-op membership', 'open-bulk-buying' );
	return $columns;
}

function openbb_user_access_column_value( $value, string $column, int $user_id ): string {
	if ( 'openbb_membership' !== $column ) {
		return (string) $value;
	}

	$status = OPENBB_Access::get_status( $user_id );
	if ( '' === $status ) {
		return '';
	}

	$labels = OPENBB_Roles::labels();
	$roles  = array_map(
		static function ( string $role ) use ( $labels ): string {
			return $labels[ $role ] ?? $role;
		},
		OPENBB_Access::get_roles( $user_id )
	);

	return esc_html( OPENBB_Access::status_labels()[ $status ] . ( $roles ? ' (' . implode( ', ', $roles ) . ')' : '' ) );
}