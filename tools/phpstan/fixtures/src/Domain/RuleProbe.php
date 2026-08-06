<?php
/**
 * Deliberately broken code, so the rules that forbid it can be proved.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tools\PHPStan\Fixtures\Domain;

/**
 * Two architectural violations, on purpose.
 *
 * `hiveclerk.domainPurity` and `hiveclerk.noGlobalWpdb` are what make the
 * layering in CLAUDE.md a fact rather than an intention — one keeps
 * WordPress out of `src/Domain`, the other keeps SQL out of everything
 * except the persistence layer. Both are registered in
 * `phpstan.neon.dist` under `services`, and until now nothing checked
 * that they were still registered.
 *
 * That is the failure worth guarding against: a rule that stops running
 * does not fail the build, it *stops failing* it. A mistyped class name
 * or a dropped `tags` entry in the neon file would disarm the entire
 * architectural boundary while `composer analyse` went on reporting
 * "[OK] No errors", and the first evidence would be domain code that
 * imports WordPress arriving in a review months later.
 *
 * This file lives under `tools/`, which PHPStan's normal `paths` do not
 * include, so it never breaks the real run. Its *path* contains
 * `/src/Domain/` because that substring is exactly how both rules decide
 * whether they apply — which makes this fixture a test of the matching
 * as well as the registration.
 *
 * Run by `tools/verify-phpstan-rules.php`, which fails when either rule
 * goes quiet.
 */
final class RuleProbe {

	/**
	 * Calls a WordPress function from inside the domain layer.
	 *
	 * Must produce `hiveclerk.domainPurity`.
	 *
	 * @return string
	 */
	public function callsWordPress(): string {
		return (string) apply_filters( 'hiveclerk/rule_probe', 'value' );
	}

	/**
	 * Reaches for the database outside the persistence layer.
	 *
	 * Must produce `hiveclerk.noGlobalWpdb`.
	 *
	 * @return mixed
	 */
	public function touchesTheDatabase(): mixed {
		global $wpdb;

		return $wpdb;
	}
}
