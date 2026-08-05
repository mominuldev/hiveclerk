<?php
/**
 * FluentCRM and Groundhogg declarations for static analysis.
 *
 * Both are optional local integrations, not dependencies: the connectors
 * check for them at runtime and report themselves unavailable when they
 * are absent. Neither is in composer.json, so analysis has nothing to
 * resolve their symbols against.
 *
 * Referenced from phpstan.neon.dist under scanFiles. Never loaded — and
 * unlike the other stub files it carries no `defined()` guard, because a
 * file made of namespace blocks has no top level to return from. It is
 * kept out of the plugin's PSR-4 roots and out of every include path
 * instead, which is the same protection by a different route.
 *
 * The signatures are deliberately loose — mixed almost everywhere. This
 * exists so PHPStan knows the symbols exist, not to reimplement two
 * plugins' type contracts across every version a customer might have.
 * Anything narrower here would be a claim about code we do not control,
 * and the connectors already treat every return value as untrusted.
 *
 * @package Hiveclerk
 */

// phpcs:ignoreFile

namespace {

	/**
	 * FluentCRM's public API entry point.
	 *
	 * @param string $module Module name.
	 * @return mixed
	 */
	function FluentCrmApi( $module ) {
	}
}

namespace FluentCrm\App\Models {

	class Subscriber {

		/**
		 * @return mixed
		 */
		public static function count() {
		}
	}

	class SubscriberNote {

		/**
		 * @param array<string, mixed> $data Attributes.
		 * @return mixed
		 */
		public static function create( $data ) {
		}
	}
}

namespace FluentCrm\App\Services {

	class Helper {

		/**
		 * @return mixed
		 */
		public static function getContactCustomFields() {
		}
	}
}

namespace Groundhogg {

	class Contact {

		/**
		 * @param array<string, mixed>|int $data Attributes or an existing id.
		 */
		public function __construct( $data = array() ) {
		}

		/**
		 * @return mixed
		 */
		public function get_id() {
		}

		/**
		 * @return mixed
		 */
		public function exists() {
		}

		/**
		 * @param array<string, mixed> $data Attributes.
		 * @return mixed
		 */
		public function update( $data ) {
		}

		/**
		 * @param string $key   Meta key.
		 * @param mixed  $value Meta value.
		 * @return mixed
		 */
		public function update_meta( $key, $value ) {
		}

		/**
		 * @param mixed $tags Tag names or ids.
		 * @return mixed
		 */
		public function add_tag( $tags ) {
		}

		/**
		 * @param string $note      Note body.
		 * @param string $context   Where it came from.
		 * @return mixed
		 */
		public function add_note( $note, $context = 'system' ) {
		}
	}

	/**
	 * @param string|int $email Address or id.
	 * @return mixed
	 */
	function get_contactdata( $email ) {
	}
}
