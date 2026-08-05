<?php
/**
 * One item in the needs-attention queue.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * Something that wants a person, with the route to handling it.
 *
 * D11 §3 calls this a work queue rather than a notification feed, and the
 * difference is enforced here: every alert carries a `href` because an
 * item that cannot be acted on does not belong in the queue, and every
 * alert disappears from the next build once the underlying condition
 * clears. Nothing is stored — there is no read state to drift out of
 * sync with the thing it describes.
 */
final class Alert implements \JsonSerializable {

	public const SEVERITY_INFO    = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_URGENT  = 'urgent';

	/**
	 * Construct.
	 *
	 * @param string      $kind     Machine name for the condition.
	 * @param string      $title    What is happening.
	 * @param string|null $detail   The specifics, where there are any.
	 * @param string      $href     In-app route that handles it.
	 * @param string      $severity One of the SEVERITY_ constants.
	 * @param int         $count    How many of this thing there are.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $title,
		public readonly ?string $detail,
		public readonly string $href,
		public readonly string $severity = self::SEVERITY_INFO,
		public readonly int $count = 1
	) {
	}

	/**
	 * Sort weight, most urgent first.
	 *
	 * @return int
	 */
	public function weight(): int {
		return match ( $this->severity ) {
			self::SEVERITY_URGENT  => 0,
			self::SEVERITY_WARNING => 1,
			default                => 2,
		};
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'kind'     => $this->kind,
			'title'    => $this->title,
			'detail'   => $this->detail,
			'href'     => $this->href,
			'severity' => $this->severity,
			'count'    => $this->count,
		);
	}
}
