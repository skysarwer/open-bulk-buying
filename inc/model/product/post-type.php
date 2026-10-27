<?php
/**
 * Product post type, category and supplier taxonomies, and meta registration.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPENBB_PRODUCT_POST_TYPE = 'openbb_product';
const OPENBB_PRODUCT_TAXONOMY  = 'openbb_product_category';
const OPENBB_SUPPLIER_TAXONOMY = 'openbb_supplier';

function openbb_register_product_post_type(): void {
	register_post_type(
		OPENBB_PRODUCT_POST_TYPE,
		array(
			'labels'          => array(
				'name'          => __( 'Products', 'open-bulk-buying' ),
				'singular_name' => __( 'Product', 'open-bulk-buying' ),
			),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => array( 'title', 'thumbnail', 'custom-fields', 'page-attributes' ),
			'capability_type' => array( 'openbb_product', 'openbb_products' ),
			'map_meta_cap'    => true,
			'capabilities'    => openbb_product_capabilities(),
		)
	);

	register_taxonomy(
		OPENBB_PRODUCT_TAXONOMY,
		OPENBB_PRODUCT_POST_TYPE,
		array(
			'labels'       => array(
				'name'          => __( 'Product Categories', 'open-bulk-buying' ),
				'singular_name' => __( 'Product Category', 'open-bulk-buying' ),
			),
			'public'       => false,
			'hierarchical' => true,
			'show_ui'      => false,
			'show_in_rest' => false,
			'rewrite'      => false,
			'query_var'    => false,
			'capabilities' => openbb_product_term_capabilities(),
		)
	);

	register_taxonomy(
		OPENBB_SUPPLIER_TAXONOMY,
		OPENBB_PRODUCT_POST_TYPE,
		array(
			'labels'             => array(
				'name'          => __( 'Suppliers', 'open-bulk-buying' ),
				'singular_name' => __( 'Supplier', 'open-bulk-buying' ),
			),
			'public'             => false,
			'hierarchical'       => false,
			'show_ui'            => false,
			'show_in_rest'       => false,
			'rewrite'            => false,
			'query_var'          => false,
			'capabilities'       => openbb_product_term_capabilities(),
			'openbb_single_term' => true,
		)
	);

	register_term_meta(
		OPENBB_PRODUCT_TAXONOMY,
		OPENBB_META_PREFIX . 'order',
		array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => false,
			'sanitize_callback' => static function ( $value ) {
				return (int) $value;
			},
			'auth_callback'     => static function () {
				return current_user_can( 'openbb_manage_products' );
			},
		)
	);

	// Translations of the term name; the term's own name is the default text.
	foreach ( array( OPENBB_PRODUCT_TAXONOMY, OPENBB_SUPPLIER_TAXONOMY ) as $taxonomy ) {
		register_term_meta(
			$taxonomy,
			OPENBB_META_PREFIX . 'name',
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => false,
				'sanitize_callback' => 'openbb_sanitize_localized_text',
				'auth_callback'     => static function () {
					return current_user_can( 'openbb_manage_products' );
				},
			)
		);
	}

	foreach ( openbb_product_meta_fields() as $field => $args ) {
		register_post_meta(
			OPENBB_PRODUCT_POST_TYPE,
			OPENBB_META_PREFIX . $field,
			array_merge(
				array(
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => 'openbb_can_edit_product_meta',
				),
				$args
			)
		);
	}
}

/**
 * A category or supplier name as a localized map: the term name is the default text.
 */
function openbb_term_names( WP_Term $term ): array {
	$translations = get_term_meta( $term->term_id, OPENBB_META_PREFIX . 'name', true );

	return array_merge( is_array( $translations ) ? $translations : array(), array( 'default' => $term->name ) );
}

/**
 * A category or supplier name in $language (default: current language).
 */
function openbb_term_name( WP_Term $term, ?string $language = null ): string {
	return openbb_localized( openbb_term_names( $term ), $language );
}

/**
 * Every primitive product capability resolves to openbb_manage_products, so WordPress
 * roles get no access through post caps (e.g. an Editor's edit_others_posts).
 */
function openbb_product_capabilities(): array {
	return array_fill_keys(
		array(
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_private_posts',
			'delete_published_posts',
			'create_posts',
		),
		'openbb_manage_products'
	);
}

/**
 * Category and supplier terms follow product access rather than manage_categories/edit_posts.
 */
function openbb_product_term_capabilities(): array {
	return array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), 'openbb_manage_products' );
}

/**
 * Field => register_post_meta() args. Stored under OPENBB_META_PREFIX . $field.
 *
 * The post title mirrors the default name for WordPress search. Prices and charges
 * are integer minor units (e.g. cents). The price covers unit_amount of unit_type
 * (see units.php), e.g. 4000 per 50 'g'.
 */
function openbb_product_meta_fields(): array {
	$localized_text = array(
		'type'              => 'array',
		'default'           => array(),
		'sanitize_callback' => 'openbb_sanitize_localized_text',
	);

	return array(
		'name'             => $localized_text,
		'description'      => array_merge( $localized_text, array( 'sanitize_callback' => 'openbb_sanitize_localized_html' ) ),
		'brand'            => array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'unit_amount'      => array(
			'type'              => 'number',
			'default'           => OPENBB_UNIT_AMOUNT_DEFAULT,
			'sanitize_callback' => 'openbb_sanitize_unit_amount',
		),
		'unit_type'        => array(
			'type'              => 'string',
			'default'           => OPENBB_UNIT_TYPE_DEFAULT,
			'sanitize_callback' => 'openbb_sanitize_unit_type',
		),
		'price'            => array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		),
		'container_charge' => array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		),
		'active'           => array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
	);
}

function openbb_can_edit_product_meta( $allowed, $meta_key, $post_id, $user_id = 0 ): bool {
	return user_can( (int) $user_id, 'edit_post', (int) $post_id );
}

/**
 * Blocks unit meta writes that OPENBB_Product::validate_unit_change() rejects, so
 * every write path follows the rule. Save routines should call the validator first
 * to show its error, and write unit meta before the active flag.
 *
 * Hooked to 'add_post_metadata', 'update_post_metadata' and 'delete_post_metadata';
 * returning non-null short-circuits the write.
 */
function openbb_guard_product_unit_meta( $check, $object_id, $meta_key, $meta_value = null ) {
	$fields = array(
		OPENBB_META_PREFIX . 'unit_amount' => 'unit_amount',
		OPENBB_META_PREFIX . 'unit_type'   => 'unit_type',
	);
	if ( null !== $check || ! isset( $fields[ $meta_key ] ) || ! $object_id || OPENBB_PRODUCT_POST_TYPE !== get_post_type( $object_id ) ) {
		return $check;
	}

	$product = OPENBB_Product::get( (int) $object_id );
	if ( ! $product ) {
		return $check;
	}

	// Deleting the meta falls back to the registered default.
	$deleting    = 'delete_post_metadata' === current_filter();
	$unit_amount = $product->unit_amount;
	$unit_type   = $product->unit_type;
	if ( 'unit_amount' === $fields[ $meta_key ] ) {
		$unit_amount = $deleting ? OPENBB_UNIT_AMOUNT_DEFAULT : openbb_sanitize_unit_amount( $meta_value );
	} else {
		$unit_type = $deleting ? OPENBB_UNIT_TYPE_DEFAULT : openbb_sanitize_unit_type( $meta_value );
	}

	return is_wp_error( $product->validate_unit_change( $unit_amount, $unit_type ) ) ? false : $check;
}

/**
 * Enforces the custom 'openbb_single_term' register_taxonomy() arg: an object keeps at
 * most one term, and when a write would leave several, the last term requested wins.
 *
 * Hooked to 'set_object_terms', which every term write (wp_set_object_terms(),
 * wp_add_object_terms(), wp_set_post_terms(), tax_input) passes through.
 */
function openbb_enforce_single_term( $object_id, $terms, $tt_ids, $taxonomy ): void {
	$taxonomy_object = get_taxonomy( $taxonomy );
	if ( ! $taxonomy_object || empty( $taxonomy_object->openbb_single_term ) || ! $tt_ids ) {
		return;
	}

	$keep    = (int) end( $tt_ids );
	$current = wp_get_object_terms( $object_id, $taxonomy, array( 'update_term_meta_cache' => false ) );
	if ( is_wp_error( $current ) ) {
		return;
	}

	$extra = array();
	foreach ( $current as $term ) {
		if ( (int) $term->term_taxonomy_id !== $keep ) {
			$extra[] = (int) $term->term_id;
		}
	}

	if ( $extra ) {
		wp_remove_object_terms( $object_id, $extra, $taxonomy );
	}
}
