<?php
/**
 * Outbound webhook signing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Support;

/**
 * Signs an outbound payload so the receiver can prove it came from us.
 *
 * ## What the timestamp is for
 *
 * Without it, a signature is valid forever. Anyone who captures one
 * request — a proxy log, a misconfigured receiver that echoes bodies, a
 * shared staging environment — can replay `lead.qualified` at any point
 * in the future and the receiver has no way to tell. The timestamp is
 * signed alongside the body and sent beside it, so a receiver can refuse
 * anything older than a few minutes.
 *
 * The scheme matches the one D9 §4 documents: `X-HVC-Signature:
 * sha256=<hex>` over `<timestamp>.<raw body>`, with the raw body being
 * the exact bytes sent. Signing a re-encoded copy is the classic way this
 * breaks — two JSON encoders disagree about `/` or unicode escaping and
 * every signature fails verification for a reason nobody can see.
 */
final class WebhookSigner {

	/**
	 * Build the headers for one delivery.
	 *
	 * @param string $body      Exact request body.
	 * @param string $secret    Shared signing secret.
	 * @param int    $timestamp Unix time.
	 * @param string $event     Event name, sent for routing.
	 * @return array<string, string>
	 */
	public function headers( string $body, string $secret, int $timestamp, string $event ): array {
		$headers = array(
			'X-HVC-Event'     => $event,
			'X-HVC-Timestamp' => (string) $timestamp,
		);

		if ( '' !== $secret ) {
			$headers['X-HVC-Signature'] = 'sha256=' . $this->signature( $body, $secret, $timestamp );
		}

		return $headers;
	}

	/**
	 * The hex signature for a body.
	 *
	 * @param string $body      Exact request body.
	 * @param string $secret    Shared signing secret.
	 * @param int    $timestamp Unix time.
	 * @return string
	 */
	public function signature( string $body, string $secret, int $timestamp ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * A secret worth generating on the customer's behalf.
	 *
	 * Generated rather than asked for. An operator invited to invent a
	 * signing secret types the site name, and a webhook signed with a
	 * guessable secret is a webhook anybody can forge.
	 *
	 * @return string
	 */
	public function generateSecret(): string {
		return 'whsec_' . bin2hex( random_bytes( 24 ) );
	}
}
