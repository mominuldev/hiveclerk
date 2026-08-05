<?php
/**
 * The resolver used when leads are not available.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * Answers "nobody" to every question, without failing.
 *
 * Bound by the core service provider and replaced by the leads module.
 * A site that filters the leads module out still opens sessions and
 * still holds conversations; what it loses is the visit history behind
 * them, which is the correct thing to lose.
 */
final class NullVisitorResolver implements VisitorResolverInterface {

	/**
	 * No visitor, ever.
	 *
	 * @param string|null          $uuid    Ignored.
	 * @param array<string, mixed> $context Ignored.
	 * @return Visitor|null
	 */
	public function resolve( ?string $uuid, array $context = array() ): ?Visitor {
		unset( $uuid, $context );

		return null;
	}
}
