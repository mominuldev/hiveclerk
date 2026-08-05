<?php
/**
 * Website crawling.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\PageFetcher;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\RobotsRules;
use Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl\UrlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\HtmlNormaliser;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use RuntimeException;

/**
 * Crawls a website (FR-KB-02).
 *
 * This is the extractor that talks to somebody else's server, which
 * makes it the one with an obligation to behave. Four constraints, all
 * of them enforced here rather than left to configuration:
 *
 * - **robots.txt is obeyed**, including Crawl-delay.
 * - **Requests are paced.** A crawl that issues requests as fast as PHP
 *   can make them looks exactly like a denial of service, and small
 *   hosts respond by blocking the address — which is the customer's own
 *   server.
 * - **There is always a page cap.** Not as a default the customer can
 *   remove, but as a hard ceiling. A crawl that finds a calendar widget
 *   generates URLs indefinitely, and an unbounded crawl of an infinite
 *   URL space is how a background job runs for a week.
 * - **URLs are canonicalised before they are queued**, so one page is
 *   fetched once.
 *
 * Sitemaps are preferred over link discovery when one is available: it
 * is the list of pages the site says are worth reading, it excludes the
 * tag archives and paginated listings that make up most of a link crawl,
 * and it is one request instead of hundreds.
 */
final class WebCrawlExtractor extends AbstractExtractor {

	/**
	 * Pages a single crawl may fetch, whatever the configuration says.
	 */
	public const HARD_PAGE_CAP = 2000;

	/**
	 * Default pages when the source does not say.
	 */
	public const DEFAULT_PAGES = 100;

	/**
	 * Default link depth from the starting URL.
	 */
	public const DEFAULT_DEPTH = 3;

	/**
	 * Shortest pause between requests, in milliseconds.
	 */
	private const MIN_DELAY_MS = 200;

	/**
	 * Sitemap entries read before giving up on one file.
	 */
	private const SITEMAP_LIMIT = 5000;

	/**
	 * Construct.
	 *
	 * @param PageFetcher    $fetcher    HTTP.
	 * @param HtmlNormaliser $normaliser HTML normaliser.
	 * @param UrlNormaliser  $urls       URL canonicaliser.
	 */
	public function __construct(
		private readonly PageFetcher $fetcher,
		private readonly HtmlNormaliser $normaliser,
		private readonly UrlNormaliser $urls,
	) {
	}

	public function type(): SourceType {
		return SourceType::WebsiteCrawl;
	}

	public function estimate( KnowledgeSource $source ): ?int {
		$start = $this->startUrl( $source );

		if ( null === $start ) {
			return null;
		}

		$robots = $this->robots( $start );
		$urls   = $this->fromSitemaps( $start, $robots, $source );

		// Only a sitemap gives a real number before the crawl runs. A link
		// crawl's size is unknowable until it has finished, and a
		// fabricated total produces a progress bar that stalls at 90%.
		return array() === $urls ? null : min( count( $urls ), $this->pageCap( $source ) );
	}

	/**
	 * Crawl and yield one document per page.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		$start = $this->startUrl( $source );

		if ( null === $start ) {
			throw new RuntimeException( 'This source has no valid starting URL.' );
		}

		$robots = $this->robots( $start );
		$cap    = $this->pageCap( $source );
		$depth  = max( 0, min( 10, $this->intConfig( $source, 'max_depth', self::DEFAULT_DEPTH ) ) );
		$delay  = $this->delay( $source, $robots );

		$queue   = array();
		$seen    = array();
		$fetched = 0;

		foreach ( $this->fromSitemaps( $start, $robots, $source ) as $url ) {
			$queue[]      = array( $url, 0 );
			$seen[ $url ] = true;
		}

		if ( array() === $queue ) {
			$queue[]        = array( $start, 0 );
			$seen[ $start ] = true;
		}

		while ( array() !== $queue && $fetched < $cap ) {
			[ $url, $level ] = array_shift( $queue );

			if ( ! $this->permitted( $url, $start, $robots, $source ) ) {
				continue;
			}

			// Paced before the request rather than after, so the very
			// first burst is throttled too.
			if ( $fetched > 0 ) {
				usleep( $delay * 1000 );
			}

			$result = $this->fetcher->get( $url, $this->userAgent() );
			++$fetched;

			if ( ! $result->success ) {
				continue;
			}

			if ( $level < $depth ) {
				foreach ( $this->links( $result->body, $url ) as $link ) {
					if ( isset( $seen[ $link ] ) ) {
						continue;
					}

					$seen[ $link ] = true;
					$queue[]       = array( $link, $level + 1 );
				}
			}

			$document = $this->toDocument( $result->url, $result->body, $level );

			if ( null !== $document ) {
				yield $document;
			}
		}
	}

	/**
	 * Turn one fetched page into a document.
	 *
	 * @param string $url   Page URL.
	 * @param string $html  Markup.
	 * @param int    $depth How far from the start it was found.
	 * @return ExtractedDocument|null
	 */
	private function toDocument( string $url, string $html, int $depth ): ?ExtractedDocument {
		if ( $this->isNoIndex( $html ) ) {
			// A page carrying a noindex directive has asked not to be in
			// search results. Indexing it into an answer engine is the
			// same request being ignored in a newer form.
			return null;
		}

		$title  = $this->title( $html, $url );
		$blocks = $this->normaliser->toBlocks( $html, true, array( $title ) );

		if ( array() === $blocks ) {
			return null;
		}

		return new ExtractedDocument(
			// The canonical URL is the identity. It is what makes a
			// re-crawl update pages instead of duplicating them.
			externalId: $url,
			title: $title,
			text: NormalisedText::fromBlocks( $blocks ),
			url: $url,
			metadata: array(
				'kind'  => 'page',
				'depth' => $depth,
			),
		);
	}

	/**
	 * Whether a URL may be fetched.
	 *
	 * @param string          $url    Candidate.
	 * @param string          $start  Starting URL.
	 * @param RobotsRules     $robots Rules.
	 * @param KnowledgeSource $source Source.
	 * @return bool
	 */
	private function permitted( string $url, string $start, RobotsRules $robots, KnowledgeSource $source ): bool {
		if ( ! $this->urls->sameHost( $url, $start ) ) {
			return false;
		}

		$path  = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' );
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( is_string( $query ) && '' !== $query ) {
			$path .= '?' . $query;
		}

		if ( ! $robots->allows( $path ) ) {
			return false;
		}

		foreach ( $this->stringList( $source, 'exclude' ) as $pattern ) {
			if ( $this->matchesPattern( $pattern, $url ) ) {
				return false;
			}
		}

		$include = $this->stringList( $source, 'include' );

		if ( array() === $include ) {
			return true;
		}

		foreach ( $include as $pattern ) {
			if ( $this->matchesPattern( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a URL against a wildcard pattern from the settings form.
	 *
	 * Wildcards rather than regular expressions. The field is filled in
	 * by a shop owner, and an invalid regular expression would either
	 * throw or silently match nothing.
	 *
	 * @param string $pattern Pattern.
	 * @param string $url     URL.
	 * @return bool
	 */
	private function matchesPattern( string $pattern, string $url ): bool {
		$quoted = array_map(
			static fn ( string $part ): string => preg_quote( $part, '#' ),
			explode( '*', $pattern )
		);

		return 1 === preg_match( '#' . implode( '.*', $quoted ) . '#i', $url );
	}

	/**
	 * Read robots.txt for the site being crawled.
	 *
	 * @param string $start Starting URL.
	 * @return RobotsRules
	 */
	private function robots( string $start ): RobotsRules {
		$parts = wp_parse_url( $start );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return RobotsRules::permissive();
		}

		$url = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/robots.txt';

		$body = $this->fetcher->getRaw( $url, $this->userAgent() );

		return null === $body ? RobotsRules::permissive() : RobotsRules::parse( $body, $this->token() );
	}

	/**
	 * Collect URLs from the site's sitemaps.
	 *
	 * @param string          $start  Starting URL.
	 * @param RobotsRules     $robots Rules, which may declare sitemaps.
	 * @param KnowledgeSource $source Source.
	 * @return array<int, string>
	 */
	private function fromSitemaps( string $start, RobotsRules $robots, KnowledgeSource $source ): array {
		if ( ! $this->boolConfig( $source, 'use_sitemap', true ) ) {
			return array();
		}

		$parts = wp_parse_url( $start );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return array();
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		$candidates = array_merge(
			$robots->sitemaps,
			array( $origin . '/sitemap.xml', $origin . '/wp-sitemap.xml', $origin . '/sitemap_index.xml' )
		);

		$found = array();

		foreach ( $candidates as $candidate ) {
			foreach ( $this->readSitemap( $candidate, 0 ) as $url ) {
				$found[ $url ] = true;

				if ( count( $found ) >= self::SITEMAP_LIMIT ) {
					break 2;
				}
			}

			// One sitemap that produced results is enough. Trying the rest
			// would re-read the same URLs through a different index.
			if ( array() !== $found ) {
				break;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Read one sitemap, following index files one level down.
	 *
	 * @param string $url   Sitemap URL.
	 * @param int    $depth Recursion depth.
	 * @return array<int, string>
	 */
	private function readSitemap( string $url, int $depth ): array {
		// Sitemap indexes point at sitemaps. Two levels is the structure
		// the specification describes; deeper is either a mistake or a
		// loop, and following it would be unbounded.
		if ( $depth > 1 ) {
			return array();
		}

		$body = $this->fetcher->getRaw( $url, $this->userAgent() );

		if ( null === $body || '' === trim( $body ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return array();
		}

		$urls    = array();
		$isIndex = 'sitemapindex' === $xml->getName();

		foreach ( $xml->children() as $child ) {
			$location = trim( (string) $child->loc );

			if ( '' === $location ) {
				continue;
			}

			if ( $isIndex ) {
				foreach ( $this->readSitemap( $location, $depth + 1 ) as $nested ) {
					$urls[] = $nested;
				}

				continue;
			}

			$canonical = $this->urls->canonical( $location );

			if ( null !== $canonical ) {
				$urls[] = $canonical;
			}
		}

		return $urls;
	}

	/**
	 * Extract crawlable links from a page.
	 *
	 * A regular expression rather than a DOM parse. The normaliser
	 * already parses the page properly for its text; doing it twice
	 * doubles the most expensive part of the crawl, and href extraction
	 * is the one HTML task a pattern handles acceptably.
	 *
	 * @param string $html Markup.
	 * @param string $base Page URL.
	 * @return array<int, string>
	 */
	private function links( string $html, string $base ): array {
		if ( 0 === preg_match_all( '/<a\b[^>]*\bhref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $html, $matches ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches[1] as $raw ) {
			$href = html_entity_decode( trim( $raw, "\"' \t\n\r" ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$resolved = $this->urls->resolve( $href, $base );

			if ( null !== $resolved ) {
				$found[ $resolved ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Whether a page asks not to be indexed.
	 *
	 * @param string $html Markup.
	 * @return bool
	 */
	private function isNoIndex( string $html ): bool {
		return 1 === preg_match(
			'/<meta[^>]+name\s*=\s*["\']?robots["\']?[^>]+content\s*=\s*["\']?[^"\'>]*noindex/i',
			$html
		);
	}

	/**
	 * The page title.
	 *
	 * @param string $html Markup.
	 * @param string $url  URL, used when there is no title.
	 * @return string
	 */
	private function title( string $html, string $url ): string {
		if ( 1 === preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $matches ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		return (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? $url );
	}

	/**
	 * The starting URL, canonicalised.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return string|null
	 */
	private function startUrl( KnowledgeSource $source ): ?string {
		$url = $this->stringConfig( $source, 'url' );

		return '' === $url ? null : $this->urls->canonical( $url );
	}

	/**
	 * How many pages this crawl may fetch.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return int
	 */
	private function pageCap( KnowledgeSource $source ): int {
		$configured = $this->intConfig( $source, 'max_pages', self::DEFAULT_PAGES );

		return max( 1, min( self::HARD_PAGE_CAP, $configured ) );
	}

	/**
	 * Milliseconds to wait between requests.
	 *
	 * @param KnowledgeSource $source Source.
	 * @param RobotsRules     $robots Rules.
	 * @return int
	 */
	private function delay( KnowledgeSource $source, RobotsRules $robots ): int {
		$configured = $this->intConfig( $source, 'delay_ms', self::MIN_DELAY_MS );
		$requested  = (int) round( $robots->crawlDelay * 1000 );

		// The site's own Crawl-delay wins when it asks for more. It is
		// the server telling us what it can take.
		return max( self::MIN_DELAY_MS, $configured, $requested );
	}

	/**
	 * The full user agent string sent with requests.
	 *
	 * Identifies the plugin and links to an explanation, so a site owner
	 * seeing it in their logs can find out what it is and how to stop it.
	 *
	 * @return string
	 */
	private function userAgent(): string {
		return sprintf(
			'%s/%s (+https://hiveclerk.com/bot)',
			$this->token(),
			defined( 'HIVECLERK_VERSION' ) ? HIVECLERK_VERSION : '1.0'
		);
	}

	/**
	 * The product token robots.txt rules are matched against.
	 *
	 * @return string
	 */
	private function token(): string {
		return 'HiveclerkBot';
	}
}
