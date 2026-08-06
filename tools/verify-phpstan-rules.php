<?php
/**
 * Asserts the two custom PHPStan rules are still doing something.
 *
 * Run with:  php tools/verify-phpstan-rules.php
 * Wired into `composer check`.
 *
 * ## Why this exists
 *
 * `hiveclerk.domainPurity` and `hiveclerk.noGlobalWpdb` are what make the
 * layering in CLAUDE.md enforceable rather than aspirational. They are
 * registered by class name in `phpstan.neon.dist`, and a rule that stops
 * being registered does not fail the build — it *stops failing* it. A
 * renamed class, a dropped `tags:` entry, a merge that ate the `services`
 * block: any of them disarms the architectural boundary while
 * `composer analyse` keeps printing "[OK] No errors".
 *
 * So a fixture that violates both rules is analysed on purpose, and this
 * script fails when either error goes missing. It is the inverse of every
 * other gate here: it fails when PHPStan is too quiet.
 *
 * No declare(strict_types=1): this is a standalone script run by PHP
 * directly, and keeping it plain makes it runnable from any working
 * directory without a bootstrap.
 *
 * @package Hiveclerk
 */

$root    = dirname( __DIR__ );
$config  = $root . '/tools/phpstan/rules-fire.neon.dist';
$real    = $root . '/phpstan.neon.dist';
$phpstan = $root . '/vendor/bin/phpstan';

$expected = array(
	'hiveclerk.domainPurity' => 'A WordPress function called inside src/Domain',
	'hiveclerk.noGlobalWpdb' => 'A $wpdb reference outside the persistence layer',
);

if ( ! is_file( $phpstan ) ) {
	fwrite( STDERR, "PHPStan is not installed. Run composer install.\n" );
	exit( 1 );
}

/*
 * The fixture config duplicates the real one's service registration,
 * because inheriting it would drag all of src into this run. Duplication
 * is only safe while the two agree: if the real config registers a rule
 * this one does not, the check would be testing a config nobody ships.
 */
$realServices    = ruleClasses( (string) file_get_contents( $real ) );
$fixtureServices = ruleClasses( (string) file_get_contents( $config ) );

sort( $realServices );
sort( $fixtureServices );

if ( $realServices !== $fixtureServices ) {
	fwrite(
		STDERR,
		"The rule list in phpstan.neon.dist and tools/phpstan/rules-fire.neon.dist disagree.\n"
			. '  shipped: ' . implode( ', ', $realServices ) . "\n"
			. '  fixture: ' . implode( ', ', $fixtureServices ) . "\n\n"
			. "Add the new rule to both, and give it a case in the fixture.\n"
	);
	exit( 1 );
}

$command = escapeshellarg( $phpstan )
	. ' analyse --no-progress --error-format=json --configuration=' . escapeshellarg( $config );

exec( $command . ' 2>/dev/null', $output, $status );

$json = json_decode( implode( "\n", $output ), true );

if ( ! is_array( $json ) || ! isset( $json['files'] ) ) {
	fwrite( STDERR, "PHPStan produced no readable output for the rule fixture.\n" );
	fwrite( STDERR, implode( "\n", $output ) . "\n" );
	exit( 1 );
}

$seen = array();

foreach ( $json['files'] as $file => $data ) {
	foreach ( $data['messages'] ?? array() as $message ) {
		if ( isset( $message['identifier'] ) ) {
			$seen[ $message['identifier'] ] = $message['message'];
		}
	}
}

$missing = array();

foreach ( $expected as $identifier => $description ) {
	if ( isset( $seen[ $identifier ] ) ) {
		printf( "  %-26s fired — %s\n", $identifier, $description );

		continue;
	}

	$missing[] = $identifier;
}

if ( array() !== $missing ) {
	fwrite(
		STDERR,
		"\nThese rules did not fire against code that violates them:\n"
			. '  ' . implode( "\n  ", $missing ) . "\n\n"
			. "That means the rule is no longer registered, no longer matches the\n"
			. "paths it is meant to guard, or has been deleted. The architectural\n"
			. "boundary it enforces is currently unenforced.\n"
	);
	exit( 1 );
}

printf( "\nBoth custom PHPStan rules are registered and firing.\n" );
exit( 0 );

/**
 * Rule class names registered in a PHPStan config.
 *
 * A deliberately small parse rather than a YAML dependency: the block is
 * a handful of `class:` lines and pulling in a parser to read them would
 * be more moving parts than the check itself.
 *
 * @param string $neon Config file contents.
 * @return array<int, string>
 */
function ruleClasses( $neon ) {
	$found = array();

	if ( preg_match_all( '/class:\s*(\S+)/', $neon, $matches ) ) {
		foreach ( $matches[1] as $class ) {
			$found[] = trim( $class );
		}
	}

	return $found;
}
