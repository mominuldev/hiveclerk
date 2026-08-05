<?php
/**
 * WordPress content extraction.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\HtmlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use WP_Post;
use WP_Query;

/**
 * Indexes posts, pages and custom post types (FR-KB-01).
 *
 * ## Content is rendered, not read raw
 *
 * `post_content` is not what a visitor sees. It contains shortcodes,
 * block comments and reusable-block references, and on a page built with
 * a page builder it can be almost empty — the actual text lives in post
 * meta and is assembled at render time. Running `the_content` produces
 * what the site actually says, which is what a question will be asked
 * about.
 *
 * The cost is that every filter on that hook runs, including other
 * plugins'. That is accepted deliberately: the alternative is indexing
 * markup no reader has ever seen.
 *
 * ## Paging is by id, not by offset
 *
 * WP_Query with a growing offset re-scans and re-sorts the whole set on
 * every page, and on a site with fifty thousand posts the last pages
 * take longer than the first ones by a wide margin. Walking forward
 * through ids keeps every batch the same cost.
 */
final class WpContentExtractor extends AbstractExtractor {

	/**
	 * Posts fetched per query.
	 */
	private const BATCH = 50;

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
		return SourceType::WpContent;
	}

	public function estimate( KnowledgeSource $source ): int {
		$query = new WP_Query(
			array_merge(
				$this->baseQuery( $source ),
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Yield one document per post.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		foreach ( array_chunk( $this->ids( $source ), self::BATCH ) as $batch ) {
			$query = new WP_Query(
				array_merge(
					$this->baseQuery( $source ),
					array(
						'post__in'       => $batch,
						'orderby'        => 'post__in',
						'posts_per_page' => count( $batch ),
						'no_found_rows'  => true,
					)
				)
			);

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$document = $this->toDocument( $post, $source );

				if ( null !== $document ) {
					yield $document;
				}
			}

			// WP_Query caches every post object it loads. Over a large
			// site that ends up being the whole posts table resident at
			// once, and the import dies on a 128 MB limit rather than on
			// anything wrong with the content.
			$this->freeMemory();
		}
	}

	/**
	 * Every post id the source's filters select.
	 *
	 * Ids first, bodies in batches afterwards.
	 *
	 * The obvious alternative — paging with an increasing offset — makes
	 * MySQL re-scan and re-sort the whole matching set on every page, so
	 * the last batch of a fifty-thousand-post site costs far more than
	 * the first. Fetching ids is a single indexed query, and fifty
	 * thousand integers is a few hundred kilobytes: cheaper than one
	 * batch of rendered post content.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<int, int>
	 */
	private function ids( KnowledgeSource $source ): array {
		$query = new WP_Query(
			array_merge(
				$this->baseQuery( $source ),
				array(
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'update_post_term_cache' => false,
				)
			)
		);

		// Walked rather than array_map'd: WP_Query::$posts is typed as
		// holding either ids or WP_Post objects, and 'fields' => 'ids' is
		// a runtime promise the type system cannot see. Casting a WP_Post
		// to int would be an error rather than an id.
		$ids = array();

		foreach ( $query->posts as $post ) {
			if ( is_int( $post ) ) {
				$ids[] = $post;
			} elseif ( is_numeric( $post ) ) {
				$ids[] = (int) $post;
			}
		}

		return $ids;
	}

	/**
	 * Turn one post into a document.
	 *
	 * @param WP_Post         $post   Post.
	 * @param KnowledgeSource $source Source.
	 * @return ExtractedDocument|null
	 */
	private function toDocument( WP_Post $post, KnowledgeSource $source ): ?ExtractedDocument {
		// The global post is set so that shortcodes and blocks resolving
		// against "the current post" render the one being indexed, rather
		// than whichever post the admin or cron request happened to be
		// about.
		//
		// Restored by hand rather than with wp_reset_postdata(), which
		// reads from the main WP_Query. In a cron run there is no main
		// query with a post in it, so resetting would leave the global
		// null and break whatever ran next.
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		try {
			/**
			 * Not our hook to prefix — it is WordPress core's, and
			 * running it is the entire point: it is what turns stored
			 * markup into what a visitor actually reads.
			 *
			 * This filter is documented in wp-includes/post-template.php
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$rendered = apply_filters( 'the_content', $post->post_content );
		} finally {
			// finally, because a filter added by another plugin is
			// entitled to throw, and leaving the global pointing at an
			// arbitrary post would corrupt every request after it.
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$blocks = $this->normaliser->toBlocks(
			is_string( $rendered ) ? $rendered : '',
			false,
			array( $post->post_title )
		);

		if ( array() === $blocks ) {
			return null;
		}

		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';

		return new ExtractedDocument(
			externalId: (string) $post->ID,
			title: get_the_title( $post ),
			text: NormalisedText::fromBlocks( $blocks ),
			url: (string) get_permalink( $post ),
			metadata: array(
				'post_type'  => $post->post_type,
				'post_date'  => $post->post_date_gmt,
				'author'     => (int) $post->post_author,
				'excerpt'    => $excerpt,
				'taxonomies' => $this->taxonomies( $post ),
			),
			language: $this->language( $post, $source ),
		);
	}

	/**
	 * Build the query arguments a source's filters describe.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<string, mixed>
	 */
	private function baseQuery( KnowledgeSource $source ): array {
		$types = $this->stringList( $source, 'post_types' );

		$args = array(
			'post_type'              => array() === $types ? array( 'post', 'page' ) : $types,
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'suppress_filters'       => false,
			// Term and meta caches are primed for the taxonomy read below;
			// without them each post costs an extra query apiece.
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
		);

		$taxQuery = $this->taxonomyFilter( $source );

		if ( array() !== $taxQuery ) {
			$args['tax_query'] = $taxQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$excluded = $this->stringList( $source, 'exclude_ids' );

		if ( array() !== $excluded ) {
			$args['post__not_in'] = array_map( 'intval', $excluded );
		}

		// Password-protected posts are excluded unconditionally. Their
		// content is behind a password precisely so that it is not
		// readable, and a clerk that answers from them has published it.
		$args['has_password'] = false;

		return $args;
	}

	/**
	 * Build a tax_query from the source's taxonomy filters.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<int|string, mixed>
	 */
	private function taxonomyFilter( KnowledgeSource $source ): array {
		$filters = $source->config['taxonomies'] ?? null;

		if ( ! is_array( $filters ) || array() === $filters ) {
			return array();
		}

		$query = array( 'relation' => 'AND' );

		foreach ( $filters as $taxonomy => $terms ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $terms ) || array() === $terms ) {
				continue;
			}

			$query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', $terms ),
			);
		}

		return count( $query ) > 1 ? $query : array();
	}

	/**
	 * The terms attached to a post, for filtering at retrieval time.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, array<int, string>>
	 */
	private function taxonomies( WP_Post $post ): array {
		$map = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			$names = array();

			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}

			if ( array() !== $names ) {
				$map[ $taxonomy ] = $names;
			}
		}

		return $map;
	}

	/**
	 * The language of a post.
	 *
	 * @param WP_Post         $post   Post.
	 * @param KnowledgeSource $source Source.
	 * @return string|null
	 */
	private function language( WP_Post $post, KnowledgeSource $source ): ?string {
		$configured = $this->stringConfig( $source, 'language' );

		if ( '' !== $configured ) {
			return $configured;
		}

		/**
		 * Filters the language recorded against an indexed post.
		 *
		 * The hook multilingual plugins need: WPML and Polylang both know
		 * the answer and neither exposes it through a core API.
		 *
		 * @param string|null $language BCP-47 tag, or null.
		 * @param WP_Post     $post     The post.
		 */
		$language = apply_filters( 'hiveclerk/knowledge/post_language', null, $post );

		return is_string( $language ) && '' !== $language ? $language : null;
	}

	/**
	 * Drop the caches a long import fills up.
	 *
	 * @return void
	 */
	private function freeMemory(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();

			return;
		}

		wp_cache_flush();
	}
}
