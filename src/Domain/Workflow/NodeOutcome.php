<?php
/**
 * What happened at one node.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * The vocabulary of the run log.
 *
 * `Skipped` and `Failed` are different answers to different questions.
 * Skipped means the engine decided not to act — a suppressed address, an
 * action whose module is not installed — and the run carries on. Failed
 * means the action tried and could not, and the run stops there. Folding
 * the two into "didn't work" is how an operator ends up debugging a
 * broken CRM connection that was actually an unsubscribed lead.
 */
enum NodeOutcome: string {

	case Entered   = 'entered';
	case Matched   = 'matched';
	case Unmatched = 'unmatched';
	case Waited    = 'waited';
	case Acted     = 'acted';
	case Skipped   = 'skipped';
	case Failed    = 'failed';
	case Finished  = 'finished';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Entered   => 'Started',
			self::Matched   => 'Yes',
			self::Unmatched => 'No',
			self::Waited    => 'Waiting',
			self::Acted     => 'Done',
			self::Skipped   => 'Skipped',
			self::Failed    => 'Failed',
			self::Finished  => 'Finished',
		};
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		if ( null === $value ) {
			return self::Entered;
		}

		return self::tryFrom( $value ) ?? self::Entered;
	}
}
