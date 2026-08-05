<?php
/**
 * Lead status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * How far a lead has travelled, independent of the pipeline board.
 *
 * Status and stage are two different things and the product needs both.
 * A stage is whatever the customer named their columns — "Demo booked",
 * "Awaiting quote" — and it changes when they reorganise their process.
 * Status is the fixed vocabulary the rest of the plugin reasons about:
 * CRM connectors map onto it, analytics counts it, and a sequence exits
 * on it. Collapsing the two would mean a customer renaming a column
 * silently changing what "converted" means in their reports.
 */
enum LeadStatus: string {

	case New         = 'new';
	case Contacted   = 'contacted';
	case Qualified   = 'qualified';
	case Unqualified = 'unqualified';
	case Converted   = 'converted';
	case Lost        = 'lost';

	/**
	 * Whether this lead is still worth working.
	 *
	 * @return bool
	 */
	public function isOpen(): bool {
		return match ( $this ) {
			self::Unqualified, self::Converted, self::Lost => false,
			default => true,
		};
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::New         => 'New',
			self::Contacted   => 'Contacted',
			self::Qualified   => 'Qualified',
			self::Unqualified => 'Unqualified',
			self::Converted   => 'Converted',
			self::Lost        => 'Lost',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::New;
	}
}
