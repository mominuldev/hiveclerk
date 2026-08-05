<?php
/**
 * PHPStan rule: confine $wpdb to the persistence layer.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fails the build when $wpdb is touched outside the database layer.
 *
 * Repositories are the only place SQL is written. Confining $wpdb is what
 * makes the "all queries go through prepare()" guarantee auditable rather
 * than a convention people forget under deadline.
 *
 * @implements Rule<Variable>
 */
final class NoGlobalWpdbRule implements Rule {

	/**
	 * Path fragments permitted to use $wpdb.
	 *
	 * uninstall.php is included because WordPress loads it standalone: the
	 * container is never booted, so there is no repository to call. It is
	 * infrastructure by definition, not an exception to the rule.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_PATHS = array(
		'/src/Database/',
		'/src/Infrastructure/WordPress/',
		'/uninstall.php',
	);

	/**
	 * Node type this rule inspects.
	 *
	 * @return class-string<Variable>
	 */
	public function getNodeType(): string {
		return Variable::class;
	}

	/**
	 * Inspect a variable reference.
	 *
	 * @param Node  $node  Node.
	 * @param Scope $scope Analysis scope.
	 * @return array<int, \PHPStan\Rules\IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( ! is_string( $node->name ) || 'wpdb' !== $node->name ) {
			return array();
		}

		if ( $this->isAllowed( $scope->getFile() ) ) {
			return array();
		}

		return array(
			RuleErrorBuilder::message(
				'$wpdb may only be used in src/Database or src/Infrastructure/Wordpress. '
				. 'Add a repository method instead of querying here.'
			)
				->identifier( 'hiveclerk.noGlobalWpdb' )
				->build(),
		);
	}

	/**
	 * Whether a file is permitted to use $wpdb.
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function isAllowed( string $file ): bool {
		$normalised = str_replace( '\\', '/', $file );

		foreach ( self::ALLOWED_PATHS as $path ) {
			if ( str_contains( $normalised, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
