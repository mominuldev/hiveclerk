<?php
/**
 * Privacy preferences (FR-SYS-04, D11 §11).
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Privacy;

use DateTimeImmutable;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;

/**
 * The four decisions a site owner makes about visitors' data.
 *
 * Each one is read somewhere real. That is not a given: before Sprint 10
 * this settings group held `anonymise_ip` and `require_consent` as
 * defaults that nothing in the codebase ever read, and
 * `delete_on_uninstall` was read by `uninstall.php` but had no default
 * and no screen. A privacy control that does nothing is worse than no
 * control at all — an operator answering a data protection questionnaire
 * reads the checkbox, not the code.
 *
 * `anonymise_ip` is gone rather than implemented, because it described a
 * choice the product does not offer: an address is salted and hashed at
 * the point it is read and the original is never held, on every path,
 * unconditionally. The replacement, `store_ip_hash`, is a real choice —
 * whether the hash is kept against the visitor and session rows at all.
 * Rate limiting is unaffected either way: it derives its own key from the
 * live request and has never read the stored column.
 */
final class PrivacySettings {

	/**
	 * Longest retention a site may configure, in months.
	 *
	 * Mirrors {@see \Hiveclerk\Modules\Chat\Services\RetentionService::MAX_MONTHS}.
	 */
	public const MAX_MONTHS = 60;

	/**
	 * Construct.
	 *
	 * @param SettingsRepository $settings Settings.
	 * @param ClockInterface     $clock    Clock.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * The stored preferences, defaults filled in.
	 *
	 * @return array{retention_months: int, store_ip_hash: bool, require_consent: bool, consent_text: string|null, delete_on_uninstall: bool}
	 */
	public function current(): array {
		return array(
			'retention_months'    => $this->months(),
			'store_ip_hash'       => (bool) $this->settings->get( 'privacy.store_ip_hash', true ),
			'require_consent'     => (bool) $this->settings->get( 'privacy.require_consent', false ),
			'consent_text'        => $this->consentText(),
			/*
			 * Defaults to false and is the only setting here that can
			 * destroy data. An operator who never opens this screen must
			 * keep their conversation history when they deactivate the
			 * plugin to debug a theme.
			 */
			'delete_on_uninstall' => (bool) $this->settings->get( 'privacy.delete_on_uninstall', false ),
		);
	}

	/**
	 * Store new preferences, ignoring anything not supplied.
	 *
	 * @param array<string, mixed> $input Partial preferences.
	 * @return array{retention_months: int, store_ip_hash: bool, require_consent: bool, consent_text: string|null, delete_on_uninstall: bool}
	 */
	public function save( array $input ): array {
		$current = $this->current();

		if ( isset( $input['retention_months'] ) && is_numeric( $input['retention_months'] ) ) {
			$current['retention_months'] = max( 0, min( self::MAX_MONTHS, (int) $input['retention_months'] ) );
		}

		foreach ( array( 'store_ip_hash', 'require_consent', 'delete_on_uninstall' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$current[ $flag ] = (bool) $input[ $flag ];
			}
		}

		if ( array_key_exists( 'consent_text', $input ) ) {
			$text = is_string( $input['consent_text'] ) ? trim( $input['consent_text'] ) : '';

			$current['consent_text'] = '' === $text ? null : mb_substr( $text, 0, 500 );
		}

		$this->settings->set( 'privacy', $current );

		return $current;
	}

	/**
	 * Whether a hashed IP is kept against visitors and sessions.
	 *
	 * @return bool
	 */
	public function storesIpHash(): bool {
		return (bool) $this->settings->get( 'privacy.store_ip_hash', true );
	}

	/**
	 * Whether a visitor must accept terms before the clerk will answer.
	 *
	 * @return bool
	 */
	public function requiresConsent(): bool {
		return (bool) $this->settings->get( 'privacy.require_consent', false );
	}

	/**
	 * What the visitor is asked to accept.
	 *
	 * Falls back to a sentence rather than to nothing. A consent gate with
	 * an empty prompt is a button with no question above it, and the site
	 * owner who ticked the box would not see that until a visitor did.
	 *
	 * @return string|null
	 */
	public function consentText(): ?string {
		$text = $this->settings->get( 'privacy.consent_text', null );

		if ( is_string( $text ) && '' !== trim( $text ) ) {
			return trim( $text );
		}

		return $this->requiresConsent()
			? __( 'This chat is recorded so we can answer your question and follow up. Continue?', 'hiveclerk' )
			: null;
	}

	/**
	 * The instant before which conversations are deleted, or null.
	 *
	 * Computed from the setting on every read rather than stamped onto
	 * each conversation when it starts. Shortening the policy therefore
	 * purges history that already exists, which is what an operator means
	 * when they shorten it — usually because a regulator asked them to. A
	 * stamped `purge_after` would only apply to conversations that had not
	 * happened yet, which is the opposite of the promise.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function cutoff(): ?DateTimeImmutable {
		$months = $this->months();

		if ( 0 === $months ) {
			return null;
		}

		return $this->clock->now()->modify( sprintf( '-%d months', $months ) );
	}

	/**
	 * Months of history kept, or 0 for forever.
	 *
	 * @return int
	 */
	public function months(): int {
		$value = $this->settings->get( 'privacy.retention_months', 12 );

		if ( ! is_numeric( $value ) ) {
			return 12;
		}

		return max( 0, min( self::MAX_MONTHS, (int) $value ) );
	}
}
