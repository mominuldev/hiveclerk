<?php
/**
 * Audit writing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Audit;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Audit\AuditEntry;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;

/**
 * Records configuration changes.
 *
 * Sits between the controllers and the repository because everything that
 * makes an audit record safe is WordPress-aware and none of it belongs in
 * the domain: resolving the acting user, hashing the request IP, and —
 * most importantly — redacting the payload.
 *
 * The redaction is the part that matters. This log exists to record
 * changes to the settings screen that holds the customer's API key, so
 * the single most likely way to leak that key is to write it into the
 * record of it being set. Redaction happens here, at the only door into
 * the log, rather than being left to each caller to remember.
 */
final class AuditLogger {

	/**
	 * Actions worth naming as constants because more than one place
	 * writes them and a typo would silently split the history in two.
	 */
	public const PROVIDER_KEY_SET     = 'provider.key.set';
	public const PROVIDER_KEY_REMOVED = 'provider.key.removed';
	public const PROVIDER_VERIFIED    = 'provider.verified';
	public const PROVIDER_MODEL_SET   = 'provider.model.set';
	public const SETTINGS_UPDATED     = 'settings.updated';

	/**
	 * Field names whose values never get written.
	 *
	 * Matched as substrings against lower-cased keys, so `api_key`,
	 * `refresh_token` and `client_secret` are all caught without needing
	 * an exhaustive list of the exact names every integration will use.
	 *
	 * `webhook` is here because a Slack incoming-webhook URL is a bearer
	 * credential wearing an address's clothes: anyone holding it can post
	 * into the customer's channel, and nothing about the string looks like
	 * a secret. It matched none of the other hints, so it landed in the
	 * audit table in full and went out through the
	 * `hiveclerk/audit/recorded` action, which this class's own docblock
	 * promises is free of secrets. It covers both names this codebase uses,
	 * `slack_webhook` and `webhook`.
	 *
	 * A bare `url` is deliberately *not* a hint. It would redact
	 * `page_url`, `site_url`, `document_url` and every other address the
	 * log keeps as context — none of which is a credential, all of which
	 * are the detail that makes an entry worth reading. Redacting the
	 * whole audit log to catch one field would trade a working control for
	 * a broken one.
	 */
	private const SECRET_HINTS = array(
		'key',
		'secret',
		'token',
		'password',
		'passwd',
		'credential',
		'authorization',
		'signature',
		'webhook',
	);

	/**
	 * Longest user agent worth keeping.
	 *
	 * The column holds 500; truncating here means a longer one is stored
	 * short rather than rejected by MySQL in strict mode.
	 */
	private const MAX_USER_AGENT = 500;

	/**
	 * Construct.
	 *
	 * @param AuditRepositoryInterface $repository Storage.
	 * @param ClockInterface           $clock      Clock.
	 */
	public function __construct(
		private readonly AuditRepositoryInterface $repository,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Record a change.
	 *
	 * @param string               $action     Dotted action name.
	 * @param array<string, mixed> $changes    What changed; redacted here.
	 * @param string|null          $objectType What kind of thing changed.
	 * @param int|null             $objectId   Which one.
	 * @return void
	 */
	public function record(
		string $action,
		array $changes = array(),
		?string $objectType = null,
		?int $objectId = null
	): void {
		$entry = new AuditEntry(
			action: $action,
			userId: self::currentUserId(),
			objectType: $objectType,
			objectId: $objectId,
			changes: self::redact( $changes ),
			ipHash: self::hashedIp(),
			userAgent: self::userAgent(),
			createdAt: $this->clock->nowSql()
		);

		$this->repository->append( $entry );

		/**
		 * Fires after a change is recorded.
		 *
		 * Security plugins and SIEM forwarders hook here. The entry is
		 * already redacted, so a listener cannot be handed a secret it
		 * would then ship off-site.
		 *
		 * @param AuditEntry $entry Recorded entry.
		 */
		do_action( 'hiveclerk/audit/recorded', $entry );
	}

	/**
	 * Replace secret-looking values, recursively.
	 *
	 * The *presence* of the field is kept — knowing that a key was
	 * changed is the whole point — while the value is replaced. Booleans
	 * and nulls pass through, because "the key was cleared" is
	 * information the log should carry and is not itself a secret.
	 *
	 * @param array<string, mixed> $changes Raw payload.
	 * @return array<string, mixed>
	 */
	public static function redact( array $changes ): array {
		$clean = array();

		foreach ( $changes as $key => $value ) {
			$stringKey = (string) $key;

			if ( is_array( $value ) ) {
				$clean[ $stringKey ] = self::redact( $value );

				continue;
			}

			if ( is_string( $value ) && '' !== $value && self::looksSecret( $stringKey ) ) {
				$clean[ $stringKey ] = '[redacted]';

				continue;
			}

			$clean[ $stringKey ] = $value;
		}

		return $clean;
	}

	/**
	 * Whether a field name suggests it holds a secret.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function looksSecret( string $key ): bool {
		$lower = strtolower( $key );

		foreach ( self::SECRET_HINTS as $hint ) {
			if ( str_contains( $lower, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The acting user, or null for a system action.
	 *
	 * @return int|null
	 */
	private static function currentUserId(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}

	/**
	 * A salted hash of the request IP.
	 *
	 * Salted with the site's own secret so the hashes cannot be reversed
	 * with a rainbow table of the four billion IPv4 addresses, which an
	 * unsalted SHA-256 of an IP address plainly can be. `wp_salt()` is
	 * used rather than the AUTH_SALT constant because core falls back to a
	 * generated per-install value, where reading the constant fell back to
	 * an empty string and produced exactly the reversible hash this exists
	 * to avoid.
	 *
	 * @return string|null
	 */
	private static function hashedIp(): ?string {
		$remote = $_SERVER['REMOTE_ADDR'] ?? '';

		if ( ! is_string( $remote ) || '' === $remote ) {
			return null;
		}

		$ip = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );

		if ( ! is_string( $ip ) ) {
			return null;
		}

		return hash( 'sha256', wp_salt( 'auth' ) . '|' . $ip );
	}

	/**
	 * The request user agent, truncated.
	 *
	 * @return string|null
	 */
	private static function userAgent(): ?string {
		$raw = $_SERVER['HTTP_USER_AGENT'] ?? '';

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$clean = sanitize_text_field( wp_unslash( $raw ) );

		return '' === $clean ? null : substr( $clean, 0, self::MAX_USER_AGENT );
	}
}
