<?php
/**
 * The outcome of fetching one URL.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl;

/**
 * What came back from a URL.
 *
 * Three outcomes rather than two. "Skipped" is separate from "failed"
 * because a linked PDF is not a broken page, and reporting it as one
 * would fill a crawl report with errors the customer cannot act on and
 * bury the redirect loop that they can.
 */
final class FetchResult {

	private function __construct(
		public readonly string $url,
		public readonly string $body,
		public readonly int $status,
		public readonly bool $success,
		public readonly bool $skipped,
		public readonly string $reason,
	) {
	}

	/**
	 * A page that was read.
	 *
	 * @param string $url    URL.
	 * @param string $body   Markup.
	 * @param int    $status HTTP status.
	 * @return self
	 */
	public static function ok( string $url, string $body, int $status ): self {
		return new self( $url, $body, $status, true, false, '' );
	}

	/**
	 * A URL that could not be read.
	 *
	 * @param string $url    URL.
	 * @param string $reason What went wrong.
	 * @param int    $status HTTP status, when there was one.
	 * @return self
	 */
	public static function failed( string $url, string $reason, int $status = 0 ): self {
		return new self( $url, '', $status, false, false, $reason );
	}

	/**
	 * A URL that was deliberately not read.
	 *
	 * @param string $url    URL.
	 * @param string $reason Why.
	 * @return self
	 */
	public static function skipped( string $url, string $reason ): self {
		return new self( $url, '', 0, false, true, $reason );
	}
}
