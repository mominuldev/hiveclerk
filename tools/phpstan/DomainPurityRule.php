<?php
/**
 * PHPStan rule: keep src/Domain framework-free.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fails the build when a WordPress function is called inside src/Domain.
 *
 * This is the rule that makes the SaaS extraction real rather than
 * aspirational. The domain layer must remain portable: if it can run
 * inside WordPress and nowhere else, then "swap the bindings" becomes
 * "rewrite the product".
 *
 * @implements Rule<FuncCall>
 */
final class DomainPurityRule implements Rule {

	/**
	 * Function name prefixes that only exist in WordPress.
	 *
	 * @var array<int, string>
	 */
	private const BANNED_PREFIXES = array(
		'wp_',
		'esc_',
		'sanitize_',
		'wc_',
		'get_post',
		'get_term',
		'get_user',
		'get_option',
		'update_option',
		'delete_option',
		'add_option',
	);

	/**
	 * Exact function names that only exist in WordPress.
	 *
	 * @var array<int, string>
	 */
	private const BANNED_FUNCTIONS = array(
		'add_action',
		'add_filter',
		'do_action',
		'apply_filters',
		'remove_action',
		'remove_filter',
		'current_user_can',
		'is_wp_error',
		'admin_url',
		'rest_url',
		'home_url',
		'site_url',
		'plugin_dir_path',
		'plugin_dir_url',
		'get_bloginfo',
		'is_admin',
		'set_transient',
		'get_transient',
		'delete_transient',
		'__',
		'_e',
		'_x',
		'_n',
		'esc_html__',
		'esc_html_e',
		'esc_attr__',
	);

	/**
	 * Node type this rule inspects.
	 *
	 * @return class-string<FuncCall>
	 */
	public function getNodeType(): string {
		return FuncCall::class;
	}

	/**
	 * Inspect a function call.
	 *
	 * @param Node  $node  Node.
	 * @param Scope $scope Analysis scope.
	 * @return array<int, \PHPStan\Rules\IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( ! $this->inDomainLayer( $scope->getFile() ) ) {
			return array();
		}

		if ( ! $node->name instanceof Name ) {
			return array();
		}

		$function = $node->name->toString();

		if ( ! $this->isWordPressFunction( $function ) ) {
			return array();
		}

		return array(
			RuleErrorBuilder::message(
				sprintf(
					'%s() is a WordPress function and src/Domain must stay framework-free. '
					. 'Move this behind an interface in src/Domain and implement it in src/Infrastructure.',
					$function
				)
			)
				->identifier( 'hiveclerk.domainPurity' )
				->build(),
		);
	}

	/**
	 * Whether a file lives in the domain layer.
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function inDomainLayer( string $file ): bool {
		$normalised = str_replace( '\\', '/', $file );

		return str_contains( $normalised, '/src/Domain/' );
	}

	/**
	 * Whether a function name belongs to WordPress.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	private function isWordPressFunction( string $function ): bool {
		if ( in_array( $function, self::BANNED_FUNCTIONS, true ) ) {
			return true;
		}

		foreach ( self::BANNED_PREFIXES as $prefix ) {
			if ( str_starts_with( $function, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
