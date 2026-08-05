<?php
/**
 * Integration suite bootstrap.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * Deliberately does NOT define ABSPATH, HIVECLERK_VERSION or any of the
 * other constants the unit bootstrap fakes.
 *
 * That is the whole reason the two suites have separate bootstraps. The
 * unit bootstrap defines ABSPATH as a path that does not exist, so units
 * can run without a WordPress at all — and wp-load.php then dutifully
 * tries to require wp-includes from it. Real WordPress must define its own
 * constants, which means it cannot share a process with the fakes.
 *
 * WordPress is loaded lazily by WordPressTestCase rather than here, so
 * that a missing installation produces skipped tests with a clear message
 * instead of a fatal before PHPUnit has printed anything.
 */
