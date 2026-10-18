<?php
/**
 * Member fields. WordPress owns identity (email, names, password); co-op fields are
 * per-site user meta, keyed with the site's table prefix like core's
 * {prefix}capabilities, so a co-op role on one site of a network means nothing on another.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field => args. 'editable' fields may be changed by the member themselves or by
 * openbb_edit_members; the rest only through the service layer (signup, rules
 * acceptance, OPENBB_Access).
 */
function openbb_member_meta_fields(): array {
	return array(
		'coop_role'         => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => array( 'OPENBB_Access', 'sanitize_role' ),
			'editable'          => false,
		),
		'grants'            => array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => array( 'OPENBB_Access', 'sanitize_grants' ),
			'editable'          => false,
		),
		'joined_at'         => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'editable'          => false,
		),
		'phone'             => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'editable'          => true,
		),
		'language'          => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_key',
			'editable'          => true,
		),
		'rules_version'     => array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'editable'          => false,
		),
		'rules_accepted_at' => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'editable'          => false,
		),
	);
}

/**
 * The user meta key for $field on the current site.
 */
function openbb_member_meta_key( string $field ): string {
	global $wpdb;

	return $wpdb->get_blog_prefix() . OPENBB_META_PREFIX . $field;
}

/**
 * Reads the per-site key only. Unlike get_user_option(), never falls back to a
 * network-wide key, which would leak a value from another site.
 */
function openbb_get_member_field( int $user_id, string $field ) {
	return get_user_meta( $user_id, openbb_member_meta_key( $field ), true );
}

function openbb_update_member_field( int $user_id, string $field, $value ): bool {
	$key = openbb_member_meta_key( $field );

	return ( metadata_exists( 'user', $user_id, $key ) && get_user_meta( $user_id, $key, true ) === $value )
		|| false !== update_user_meta( $user_id, $key, $value );
}

/**
 * Whether $email is a placeholder for a member without an email address. Placeholders use
 * the reserved `.invalid` TLD (RFC 2606), which can never be delivered.
 */
function openbb_is_placeholder_email( string $email ): bool {
	return (bool) preg_match( '/\.invalid$/i', trim( $email ) );
}

/**
 * Whether plugin email may be sent to a user. All member email should check this rather
 * than assume every address works.
 */
function openbb_can_email_member( int $user_id ): bool {
	$user = get_userdata( $user_id );
	$can  = $user && is_email( $user->user_email ) && ! openbb_is_placeholder_email( $user->user_email );

	/**
	 * @param bool $can
	 * @param int  $user_id
	 */
	return (bool) apply_filters( 'openbb_can_email_member', $can, $user_id );
}

function openbb_register_member_meta(): void {
	foreach ( openbb_member_meta_fields() as $field => $args ) {
		register_meta(
			'user',
			openbb_member_meta_key( $field ),
			array(
				'type'              => $args['type'],
				'single'            => true,
				'default'           => $args['default'],
				'show_in_rest'      => false,
				'sanitize_callback' => $args['sanitize_callback'],
				'auth_callback'     => $args['editable'] ? 'openbb_can_edit_member_meta' : '__return_false',
			)
		);
	}
}

/**
 * Restricts the core meta API (which already requires edit_user) to editable fields
 * of members the user may edit. Co-op screens check openbb_edit_member themselves.
 */
function openbb_can_edit_member_meta( $allowed, $meta_key, $object_id, $user_id = 0 ): bool {
	return user_can( (int) $user_id, 'openbb_edit_member', (int) $object_id );
}
