<?php
/**
 * Missing container entry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when an identifier is not registered in the container.
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface {
}
