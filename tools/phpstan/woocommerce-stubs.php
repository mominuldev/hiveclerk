<?php
/**
 * WooCommerce declarations for static analysis.
 *
 * WooCommerce is an optional integration, not a dependency: the product
 * extractor checks for it at runtime and reports itself unavailable when
 * it is absent. It is therefore not in composer.json, and analysis has
 * nothing to resolve its symbols against.
 *
 * Referenced from phpstan.neon.dist under scanFiles. Never loaded — if
 * this file were ever included at runtime it would fatal on any site
 * that does have WooCommerce, which is the point of the guard below.
 *
 * The signatures are deliberately loose. This exists so PHPStan knows the
 * symbols exist, not to reimplement WooCommerce's type contract; anything
 * narrower here would be a claim about a version we do not control.
 *
 * @package Hiveclerk
 */

// phpcs:ignoreFile

if ( ! defined( 'HIVECLERK_PHPSTAN_STUBS' ) ) {
	return;
}

class WooCommerce {
}

class WC_Product {

	public function get_id(): int {
	}

	public function get_name(): string {
	}

	public function get_sku(): string {
	}

	public function get_type(): string {
	}

	public function get_description(): string {
	}

	public function get_short_description(): string {
	}

	public function get_price(): string {
	}

	public function get_regular_price(): string {
	}

	public function get_sale_price(): string {
	}

	public function get_stock_status(): string {
	}

	public function get_stock_quantity(): ?int {
	}

	/**
	 * @return array<string, object>
	 */
	public function get_attributes(): array {
	}

	public function get_attribute( string $name ): string {
	}
}

/**
 * Returns a plain array normally, and a stdClass carrying a `products`
 * property when called with 'paginate'. Both are declared because the
 * callers have to handle both — a stub that promised only an array would
 * make the guard against the other look like dead code.
 *
 * @param array<string, mixed> $args
 * @return array<int, mixed>|stdClass
 */
function wc_get_products( array $args ): array|stdClass {
}

function wc_get_product( mixed $product = false ): WC_Product|false {
}

function wc_attribute_label( string $name, mixed $product = false ): string {
}
