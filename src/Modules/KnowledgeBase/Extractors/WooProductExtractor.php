<?php
/**
 * WooCommerce product extraction.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\HtmlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;
use WC_Product;

/**
 * Indexes WooCommerce products (FR-KB-01, question Q-3).
 *
 * **Read-only, and that is a product decision rather than a technical
 * limit.** Nothing here writes to a product, an order or a customer.
 * Store owners are being asked to point an AI at their catalogue; the
 * answer to "can it change my prices" has to be no, and has to be
 * verifiable by reading one file.
 *
 * ## Price and stock are indexed as metadata, not as text
 *
 * Both change without the description changing. Embedded into the chunk
 * text, every price change would invalidate the chunk's vector and cost
 * a re-embed of the whole catalogue on any day the shop ran a sale.
 * Retrieval reads them live from the metadata instead, so the answer is
 * current without the index being rebuilt.
 */
final class WooProductExtractor extends AbstractExtractor {

	/**
	 * Products fetched per query.
	 */
	private const BATCH = 40;

	/**
	 * Construct.
	 *
	 * @param HtmlNormaliser $normaliser HTML normaliser.
	 */
	public function __construct(
		private readonly HtmlNormaliser $normaliser
	) {
	}

	public function type(): SourceType {
		return SourceType::WooProducts;
	}

	public function isAvailable(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	public function unavailableReason(): string {
		return $this->isAvailable()
			? ''
			: 'WooCommerce is not active on this site, so there is no catalogue to index.';
	}

	public function estimate( KnowledgeSource $source ): ?int {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		return count( $this->ids( $source ) );
	}

	/**
	 * Yield one document per product.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		if ( ! $this->isAvailable() ) {
			return;
		}

		foreach ( array_chunk( $this->ids( $source ), self::BATCH ) as $batch ) {
			foreach ( $batch as $id ) {
				$product = wc_get_product( $id );

				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$document = $this->toDocument( $product );

				if ( null !== $document ) {
					yield $document;
				}
			}

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
	}

	/**
	 * Turn one product into a document.
	 *
	 * @param WC_Product $product Product.
	 * @return ExtractedDocument|null
	 */
	private function toDocument( WC_Product $product ): ?ExtractedDocument {
		$title  = $product->get_name();
		$blocks = array();

		$short = $product->get_short_description();

		if ( '' !== trim( $short ) ) {
			foreach ( $this->normaliser->toBlocks( $short, false, array( $title, 'Summary' ) ) as $block ) {
				$blocks[] = $block;
			}
		}

		$description = $product->get_description();

		if ( '' !== trim( $description ) ) {
			foreach ( $this->normaliser->toBlocks( $description, false, array( $title, 'Description' ) ) as $block ) {
				$blocks[] = $block;
			}
		}

		foreach ( $this->attributeBlocks( $product, $title ) as $block ) {
			$blocks[] = $block;
		}

		if ( array() === $blocks ) {
			// A product with no prose at all. Indexing the title alone
			// would produce a chunk that matches any query mentioning the
			// product name and answers none of them.
			return null;
		}

		return new ExtractedDocument(
			externalId: 'product-' . $product->get_id(),
			title: $title,
			text: NormalisedText::fromBlocks( $blocks ),
			url: (string) get_permalink( $product->get_id() ),
			metadata: array(
				'kind'           => 'product',
				'product_id'     => $product->get_id(),
				'sku'            => $product->get_sku(),
				'product_type'   => $product->get_type(),
				// Volatile fields, deliberately not in the indexed text.
				'price'          => $product->get_price(),
				'regular_price'  => $product->get_regular_price(),
				'sale_price'     => $product->get_sale_price(),
				'stock_status'   => $product->get_stock_status(),
				'stock_quantity' => $product->get_stock_quantity(),
				'categories'     => $this->terms( $product->get_id(), 'product_cat' ),
				'tags'           => $this->terms( $product->get_id(), 'product_tag' ),
			),
		);
	}

	/**
	 * Product attributes as readable blocks.
	 *
	 * "Material: merino wool" answers a question; "merino wool" on its
	 * own does not, because nothing in the chunk says what it describes.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $title   Product name, for the heading path.
	 * @return array<int, TextBlock>
	 */
	private function attributeBlocks( WC_Product $product, string $title ): array {
		$blocks = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}

			$label  = wc_attribute_label( $attribute->get_name(), $product );
			$values = $product->get_attribute( $attribute->get_name() );

			if ( '' === trim( (string) $values ) ) {
				continue;
			}

			$blocks[] = new TextBlock(
				sprintf( '%s: %s', $label, $values ),
				array( $title, 'Specifications' )
			);
		}

		return $blocks;
	}

	/**
	 * Term names attached to a product.
	 *
	 * @param int    $productId Product id.
	 * @param string $taxonomy  Taxonomy.
	 * @return array<int, string>
	 */
	private function terms( int $productId, string $taxonomy ): array {
		$terms = get_the_terms( $productId, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();

		foreach ( $terms as $term ) {
			$names[] = $term->name;
		}

		return $names;
	}

	/**
	 * Product ids the source selects.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<int, int>
	 */
	private function ids( KnowledgeSource $source ): array {
		$args = array(
			'status'     => 'publish',
			'limit'      => -1,
			'return'     => 'ids',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			// Hidden products are excluded: a store hides a product so
			// that customers do not see it, and a clerk recommending one
			// has undone that decision.
			'visibility' => 'visible',
		);

		$categories = $this->stringList( $source, 'categories' );

		if ( array() !== $categories ) {
			$args['category'] = $categories;
		}

		$ids = wc_get_products( $args );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}
}
