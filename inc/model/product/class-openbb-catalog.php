<?php
/**
 * The catalog members order from: active products grouped by category.
 *
 * Categories are flattened depth-first in the order staff set (the openbb_order term
 * meta, then name), each followed by its subcategories. Products are sorted by their
 * menu order, then name. A product in several categories is listed under each one;
 * products without a category come last under "Other". Categories with no products,
 * directly or below them, are left out.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Catalog {

	/**
	 * @param OPENBB_Product[]|null $products Products to group; defaults to the active catalog.
	 * @param bool                  $once     List each product only under its first category.
	 * @return array<int, array{id: int, name: string, depth: int, products: OPENBB_Product[]}>
	 *         Sections; id 0 is the uncategorized section.
	 */
	public static function sections( ?array $products = null, bool $once = false ): array {
		if ( null !== $products ) {
			usort(
				$products,
				static function ( OPENBB_Product $a, OPENBB_Product $b ): int {
					return $a->menu_order <=> $b->menu_order ?: strnatcasecmp( $a->get_name(), $b->get_name() ) ?: $a->id <=> $b->id;
				}
			);
		}
		$products = $products ?? self::active_products();
		if ( ! $products ) {
			return array();
		}

		$by_category   = array();
		$uncategorized = array();
		foreach ( $products as $product ) {
			if ( ! $product->category_ids ) {
				$uncategorized[] = $product;
			}
			foreach ( $product->category_ids as $term_id ) {
				$by_category[ $term_id ][] = $product;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => OPENBB_PRODUCT_TAXONOMY,
				'hide_empty' => false,
			)
		);
		$terms = is_wp_error( $terms ) ? array() : $terms;

		$children = array();
		$known    = array();
		foreach ( $terms as $term ) {
			$known[ $term->term_id ] = true;
		}
		foreach ( $terms as $term ) {
			// Orphans (parent deleted) are shown at the top level.
			$parent                = isset( $known[ $term->parent ] ) ? (int) $term->parent : 0;
			$children[ $parent ][] = $term;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, array( __CLASS__, 'compare_terms' ) );
		}
		unset( $siblings );

		$sections = array();
		self::add_sections( $sections, $children, 0, 0, $by_category );
		if ( $once ) {
			$sections = self::dedupe( $sections );
		}

		if ( $uncategorized ) {
			$sections[] = array(
				'id'       => 0,
				'name'     => $sections ? __( 'Other', 'open-bulk-buying' ) : __( 'Products', 'open-bulk-buying' ),
				'depth'    => 0,
				'products' => $uncategorized,
			);
		}

		return $sections;
	}

	/**
	 * Active products in display order.
	 *
	 * @return OPENBB_Product[]
	 */
	public static function active_products(): array {
		$query = new WP_Query(
			array(
				'post_type'              => OPENBB_PRODUCT_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
					'ID'         => 'ASC',
				),
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => OPENBB_META_PREFIX . 'active',
						'value' => '1',
					),
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
			)
		);
		update_post_thumbnail_cache( $query );

		$products = array();
		foreach ( $query->posts as $post ) {
			$product = OPENBB_Product::get( $post->ID );
			if ( $product && $product->is_active() ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Appends the sections under $parent, depth first. Returns whether any were added.
	 */
	private static function add_sections( array &$sections, array $children, int $parent, int $depth, array $by_category ): bool {
		$added = false;
		foreach ( $children[ $parent ] ?? array() as $term ) {
			$at       = count( $sections );
			$products = $by_category[ $term->term_id ] ?? array();
			array_splice(
				$sections,
				$at,
				0,
				array(
					array(
						'id'       => (int) $term->term_id,
						'name'     => openbb_term_name( $term ),
						'depth'    => $depth,
						'products' => $products,
					),
				)
			);
			$has_descendants = self::add_sections( $sections, $children, (int) $term->term_id, $depth + 1, $by_category );
			if ( ! $products && ! $has_descendants ) {
				array_splice( $sections, $at, 1 );
				continue;
			}
			$added = true;
		}

		return $added;
	}

	/**
	 * Keeps each product under its first section only, then drops sections left with
	 * no products and no remaining subsections.
	 */
	private static function dedupe( array $sections ): array {
		$seen = array();
		foreach ( $sections as &$section ) {
			$section['products'] = array_values(
				array_filter(
					$section['products'],
					static function ( OPENBB_Product $product ) use ( &$seen ): bool {
						if ( isset( $seen[ $product->id ] ) ) {
							return false;
						}
						$seen[ $product->id ] = true;
						return true;
					}
				)
			);
		}
		unset( $section );

		$kept = array();
		for ( $i = count( $sections ) - 1; $i >= 0; $i-- ) {
			$next = $kept[0] ?? null;
			if ( $sections[ $i ]['products'] || ( $next && $next['depth'] > $sections[ $i ]['depth'] ) ) {
				array_unshift( $kept, $sections[ $i ] );
			}
		}

		return $kept;
	}

	private static function compare_terms( WP_Term $a, WP_Term $b ): int {
		$order = (int) get_term_meta( $a->term_id, OPENBB_META_PREFIX . 'order', true ) <=> (int) get_term_meta( $b->term_id, OPENBB_META_PREFIX . 'order', true );

		return $order ?: strnatcasecmp( $a->name, $b->name ) ?: $a->term_id <=> $b->term_id;
	}
}
