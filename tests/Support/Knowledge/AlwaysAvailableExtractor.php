<?php
/**
 * An extractor that is always ready.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Knowledge;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractorInterface;

/**
 * Lets a request reach the validation that comes after the type check.
 *
 * `SourceController::create()` refuses a source whose extractor is
 * missing or unavailable before it looks at anything else, which is the
 * right order and means a test of the *configuration* validation has to
 * get past it first.
 */
final class AlwaysAvailableExtractor implements ExtractorInterface {

	/**
	 * Construct.
	 *
	 * @param SourceType $type Type this extractor claims.
	 */
	public function __construct( private readonly SourceType $type = SourceType::Faq ) {
	}

	public function type(): SourceType {
		return $this->type;
	}

	public function isAvailable(): bool {
		return true;
	}

	public function unavailableReason(): string {
		return '';
	}

	public function estimate( KnowledgeSource $source ): ?int {
		unset( $source );

		return 0;
	}

	public function extract( KnowledgeSource $source ): iterable {
		unset( $source );

		return array();
	}
}
