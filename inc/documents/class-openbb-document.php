<?php
/**
 * A printable document: one or more pages, each rendered from a template in its own locale.
 * Access is checked by the caller before a document is built.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

abstract class OPENBB_Document {

	/**
	 * Template-safe slug, e.g. 'order-slip'.
	 */
	abstract public function type(): string;

	/**
	 * Browser tab title, in the viewer's language.
	 */
	abstract public function title(): string;

	/**
	 * Pages in print order. 'data' is called while 'locale' is active, so dates,
	 * money and labels come out in the page's language.
	 *
	 * @return array<int, array{template: string, locale: string, data: callable(): array}>
	 */
	abstract public function pages(): array;
}
