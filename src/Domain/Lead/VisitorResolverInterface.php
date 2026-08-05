<?php
/**
 * Visitor resolution port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * How the chat module asks who is typing, without depending on leads.
 *
 * A conversation needs a visitor id on it from the moment it opens —
 * that is what makes the page views somebody accumulated before they
 * said anything attachable to the lead they later become. But knowing
 * what a visitor *is* belongs to the leads module, and a widget session
 * has to keep working on a site where that module was filtered out.
 *
 * So the chat module depends on this interface, which lives in the
 * domain where both can see it, and gets {@see NullVisitorResolver} when
 * nothing better is bound.
 */
interface VisitorResolverInterface {

	/**
	 * Find or create the visitor behind a request.
	 *
	 * @param string|null          $uuid    Identifier the widget is holding, if any.
	 * @param array<string, mixed> $context Language, country, user agent, signed-in user.
	 * @return Visitor|null Null when visitor tracking is not available.
	 */
	public function resolve( ?string $uuid, array $context = array() ): ?Visitor;
}
