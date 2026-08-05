<?php
/**
 * Knowledge-source detection.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Onboarding\Services;

use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\ChunkOptions;

/**
 * Looks at the site and suggests what to index (FR-ONB-04).
 *
 * This is the mechanism behind the ten-minute activation target. The
 * difference between an operator who finishes setup and one who does not
 * is almost entirely step 3: asked to describe their knowledge from a
 * blank form, most people close the tab. Shown "24 pages, 1,180 products,
 * 86 posts" with the first three already ticked, they click Continue.
 *
 * ## Everything here is an estimate and says so
 *
 * Chunk counts come from sampling, not from chunking every post — the
 * detector runs inside the wizard's own request and cannot spend thirty
 * seconds reading a thousand products to produce a number that only
 * decides a checkbox. The sample size is reported alongside the figure so
 * the screen can say "about", which is the truthful word.
 */
final class SourceDetector {

	/**
	 * Posts read per type when measuring length.
	 */
	private const SAMPLE = 20;

	/**
	 * Post types never worth suggesting.
	 *
	 * Attachments are filenames and captions; the two WooCommerce order
	 * types are customers' own order data, which has no business being
	 * embedded and sent to a model.
	 */
	private const EXCLUDED = array(
		'attachment',
		'shop_order',
		'shop_order_placehold',
		'shop_coupon',
		'product_variation',
	);

	/**
	 * Construct.
	 *
	 * @param SettingsRepository $settings Settings, for the embedding model.
	 * @param PricingTable       $pricing  Model prices.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly PricingTable $pricing
	) {
	}

	/**
	 * Everything worth indexing on this site.
	 *
	 * @return array{suggestions: array<int, array<string, mixed>>, sitemap: array<string, mixed>|null, currency: string}
	 */
	public function detect(): array {
		$suggestions = array();

		foreach ( $this->postTypes() as $type ) {
			$suggestion = $this->forPostType( $type );

			if ( null !== $suggestion ) {
				$suggestions[] = $suggestion;
			}
		}

		// Ordered by how many chunks they contribute, which is also the
		// order of how much they cost. An operator ticking boxes down a
		// list should meet the expensive decision first, not last.
		usort(
			$suggestions,
			static fn ( array $a, array $b ): int => $b['chunks'] <=> $a['chunks']
		);

		return array(
			'suggestions' => $suggestions,
			'sitemap'     => $this->sitemap(),
			'currency'    => 'USD',
		);
	}

	/**
	 * The public post types worth offering.
	 *
	 * @return array<int, \WP_Post_Type>
	 */
	private function postTypes(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::EXCLUDED, true ) ) {
				continue;
			}

			$types[] = $type;
		}

		return $types;
	}

	/**
	 * One post type as a suggestion, or null when it holds nothing.
	 *
	 * @param \WP_Post_Type $type Post type.
	 * @return array<string, mixed>|null
	 */
	private function forPostType( \WP_Post_Type $type ): ?array {
		$counts    = wp_count_posts( $type->name );
		$published = isset( $counts->publish ) ? (int) $counts->publish : 0;

		if ( 0 === $published ) {
			return null;
		}

		$measured = $this->sampleWords( $type->name );
		$chunks   = $this->estimateChunks( $measured['average'], $published );

		return array(
			'key'           => $type->name,
			// The site's own label, not ours. A customer whose products
			// are called "Vehicles" should see "Vehicles".
			'label'         => $type->labels->name ?? $type->name,
			'source_type'   => 'product' === $type->name && $this->hasWooCommerce()
				? SourceType::WooProducts->value
				: SourceType::WpContent->value,
			'post_type'     => $type->name,
			'count'         => $published,
			'chunks'        => $chunks,
			'sampled'       => $measured['sampled'],
			'estimated_usd' => $this->estimateCost( $chunks ),
			// Pages, products and posts are ticked; everything else is
			// offered unticked. A custom type called "Testimonials" is
			// worth indexing on some sites and noise on others, and
			// guessing wrong costs the customer money on their first
			// screen.
			'recommended'   => in_array( $type->name, array( 'page', 'post', 'product' ), true ),
		);
	}

	/**
	 * The site's sitemap, if one is being served.
	 *
	 * Detected locally rather than by fetching a URL. A wizard step that
	 * makes an outbound HTTP request to the customer's own site is a
	 * wizard step that hangs on every install behind basic auth, behind a
	 * staging password, or on a host that does not resolve its own
	 * hostname from inside the network.
	 *
	 * @return array<string, mixed>|null
	 */
	private function sitemap(): ?array {
		$url = null;

		if ( function_exists( 'wp_sitemaps_get_server' ) && (bool) get_option( 'blog_public', 1 ) ) {
			$server = wp_sitemaps_get_server();

			if ( null !== $server && $server->sitemaps_enabled() ) {
				$url = home_url( '/wp-sitemap.xml' );
			}
		}

		/**
		 * Filter the sitemap URL the wizard suggests crawling.
		 *
		 * The seam for Yoast, Rank Math and All in One SEO, each of which
		 * serves its own sitemap at its own path and switches the core one
		 * off. Detecting them by name would mean a list this plugin has to
		 * keep current forever.
		 *
		 * @param string|null $url Sitemap URL, or null when none was found.
		 */
		$url = apply_filters( 'hiveclerk/onboarding/sitemap_url', $url );

		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'key'         => 'sitemap',
			'label'       => __( 'Your sitemap', 'hiveclerk' ),
			'source_type' => SourceType::WebsiteCrawl->value,
			'url'         => esc_url_raw( $url ),
			// Never pre-ticked. A crawl is the one suggestion here that
			// reaches the network, costs the most, and overlaps almost
			// entirely with the post types above it.
			'recommended' => false,
		);
	}

	/**
	 * Average word count for a post type, from a sample.
	 *
	 * @param string $postType Post type.
	 * @return array{average: int, sampled: int}
	 */
	private function sampleWords( string $postType ): array {
		$posts = get_posts(
			array(
				'post_type'        => $postType,
				'post_status'      => 'publish',
				'numberposts'      => self::SAMPLE,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		if ( array() === $posts ) {
			return array(
				'average' => 0,
				'sampled' => 0,
			);
		}

		$words = 0;

		foreach ( $posts as $post ) {
			$words += str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
		}

		return array(
			'average' => (int) round( $words / count( $posts ) ),
			'sampled' => count( $posts ),
		);
	}

	/**
	 * Chunks a post type is likely to produce.
	 *
	 * @param int $averageWords Mean words per item.
	 * @param int $count        Items.
	 * @return int
	 */
	private function estimateChunks( int $averageWords, int $count ): int {
		if ( 0 === $count ) {
			return 0;
		}

		$options  = ChunkOptions::fromConfig( array() );
		$perChunk = max( 1, (int) round( $options->maxTokens * 0.75 ) );
		$perItem  = max( 1, (int) ceil( max( 1, $averageWords ) / $perChunk ) );

		return $perItem * $count;
	}

	/**
	 * What indexing that many chunks is likely to cost.
	 *
	 * Returns null when the configured embedding model has no published
	 * price, rather than zero. A wizard that says "≈ $0.00" next to 5,000
	 * chunks is making a promise the invoice will break.
	 *
	 * @param int $chunks Chunk count.
	 * @return float|null
	 */
	private function estimateCost( int $chunks ): ?float {
		$provider = $this->settings->get( 'retrieval.embed_provider' );
		$model    = $this->settings->get( 'retrieval.embed_model' );

		if ( ! is_string( $provider ) || ! is_string( $model ) || '' === $provider || '' === $model ) {
			return null;
		}

		$options = ChunkOptions::fromConfig( array() );

		return $this->pricing->cost( $provider, $model, $chunks * $options->maxTokens );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function hasWooCommerce(): bool {
		return class_exists( 'WooCommerce' );
	}
}
