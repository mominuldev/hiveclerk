<?php
/**
 * Connection test outcome.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * What "Test connection" found.
 *
 * `account` is the part that makes the button worth pressing. "Connected"
 * on its own does not tell an operator they authorised the sandbox portal
 * instead of the live one — the account name does, and getting that wrong
 * is the failure that surfaces three weeks later as "none of our leads
 * are in HubSpot".
 */
final readonly class TestResult {

	/**
	 * Construct.
	 *
	 * @param bool        $ok      Whether the far side answered as expected.
	 * @param string      $message What to show the operator.
	 * @param string|null $account Which account or list the credentials reach.
	 * @param int|null    $records How many contacts already exist over there.
	 */
	public function __construct(
		public bool $ok,
		public string $message,
		public ?string $account = null,
		public ?int $records = null
	) {
	}

	/**
	 * A working connection.
	 *
	 * @param string      $message Message.
	 * @param string|null $account Account name.
	 * @param int|null    $records Contact count.
	 * @return self
	 */
	public static function pass( string $message, ?string $account = null, ?int $records = null ): self {
		return new self( true, $message, $account, $records );
	}

	/**
	 * A connection that did not work.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function fail( string $message ): self {
		return new self( false, $message );
	}
}
