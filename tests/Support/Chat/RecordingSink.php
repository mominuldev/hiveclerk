<?php
/**
 * A sink that records what it was told.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Modules\Chat\Support\ChatSink;

/**
 * Stands in for a connection, and can pretend to lose one.
 *
 * `stopAfter` is the interesting part: reporting the recipient as gone
 * after N deltas is how a visitor closing the tab looks from inside the
 * generation loop, and it is the only way to test that generation stops
 * rather than running to completion for nobody.
 */
final class RecordingSink implements ChatSink {

	/**
	 * Event names in the order they arrived.
	 *
	 * @var array<int, string>
	 */
	public array $events = array();

	/**
	 * Text accumulated from deltas.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * Citations delivered.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $citations = array();

	/**
	 * Report the recipient gone after this many deltas. Null never stops.
	 *
	 * @var int|null
	 */
	public ?int $stopAfter = null;

	/**
	 * Deltas seen so far.
	 *
	 * @var int
	 */
	private int $deltas = 0;

	public function start( string $messageId, string $conversationId ): bool {
		$this->events[] = 'start';

		return true;
	}

	public function delta( string $text ): bool {
		$this->events[] = 'delta';
		$this->text    .= $text;

		++$this->deltas;

		return null === $this->stopAfter || $this->deltas < $this->stopAfter;
	}

	public function replace( string $text ): bool {
		$this->events[] = 'replace';
		$this->text     = $text;

		return true;
	}

	public function citations( array $citations ): bool {
		$this->events[]  = 'citations';
		$this->citations = $citations;

		return true;
	}

	public function done( array $payload ): bool {
		$this->events[] = 'done';

		return true;
	}

	public function error( string $code, string $message, bool $recoverable = true ): bool {
		$this->events[] = 'error';

		return true;
	}
}
