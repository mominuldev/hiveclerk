<?php
/**
 * Container resolution failure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when an entry exists but cannot be resolved.
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface {
}
