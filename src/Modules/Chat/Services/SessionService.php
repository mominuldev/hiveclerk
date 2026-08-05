<?php
/**
 * Widget session issue and validation.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use DateTimeImmutable;
use Hiveclerk\Core\Privacy\IpHasher;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Session;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Lead\VisitorResolverInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * The widget's whole authentication story.
 *
 * A visitor is anonymous by design — there is no account to log into and
 * nothing about them we want to hold. What they get instead is a bearer
 * token naming exactly one conversation, and every public route reads the
 * conversation *from the token* rather than from a parameter. That is what
 * closes SEC-11: there is no identifier to tamper with, because the caller
 * never supplies one.
 *
 * ## Why the token is both signed and stored
 *
 * Either alone would do the job badly.
 *
 * A signature alone makes the token self-validating, which sounds elegant
 * until a conversation needs ending: a stateless token cannot be revoked,
 * so a leaked one stays live until it expires.
 *
 * A database row alone means every request — including the flood from
 * someone hammering the endpoint with junk — costs a query. The signature
 * is checked first precisely because it is free, so garbage is rejected
 * before it reaches MySQL. That ordering is the cost-exhaustion defence
 * (SEC-03) applied to the database rather than to the model.
 *
 * The stored value is a hash. A database dump must not hand its reader a
 * working credential for every live conversation on the site.
 */
final class SessionService {

	/**
	 * Prefix every issued token carries.
	 *
	 * Recognisable on sight, so a token pasted into a support ticket is
	 * identifiable as a session rather than as an API key.
	 */
	public const PREFIX = 'hvc_s_';

	/**
	 * How long a session lives.
	 *
	 * Long enough to survive a visitor reading three pages and coming
	 * back; short enough that a token copied out of a shared browser is
	 * useless by the evening.
	 */
	public const LIFETIME = 12 * HOUR_IN_SECONDS;

	/**
	 * Per-install signing salt.
	 */
	private const SALT_OPTION = 'hiveclerk_session_salt';

	/**
	 * Construct.
	 *
	 * @param SessionRepositoryInterface      $sessions      Session storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param ClockInterface                  $clock         Time source.
	 * @param VisitorResolverInterface        $visitors      Who is typing, when that is knowable.
	 * @param IpHasher                        $ipHasher      Address hashing, honouring the site's privacy setting.
	 */
	public function __construct(
		private readonly SessionRepositoryInterface $sessions,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly ClockInterface $clock,
		private readonly VisitorResolverInterface $visitors,
		private readonly IpHasher $ipHasher
	) {
	}

	/**
	 * Open a conversation and issue the token that opens it.
	 *
	 * @param Agent                $agent   The clerk being addressed.
	 * @param array<string, mixed> $context Page url, language.
	 * @return array{token: string, session: Session, conversation: Conversation, visitor: Visitor|null}
	 */
	public function issue( Agent $agent, array $context = array() ): array {
		$now     = $this->clock->now();
		$expires = $now->modify( '+' . self::LIFETIME . ' seconds' );

		// Resolved before the conversation is written rather than attached
		// afterwards. The visit history a scoring rule reads is the one
		// that happened *before* this conversation, and a conversation
		// saved without a visitor id has nothing to join it back to.
		$visitor = $this->visitors->resolve(
			is_string( $context['visitor'] ?? null ) ? (string) $context['visitor'] : null,
			$context
		);

		$conversation = $this->conversations->save(
			new Conversation(
				id: null,
				uuid: Uuid::generate(),
				agentId: (int) $agent->id,
				visitorId: $visitor?->id,
				leadId: $visitor?->leadId,
				language: $this->language( $context ),
				pageUrl: $this->pageUrl( $context ),
			)
		);

		$uuid    = Uuid::generate();
		$token   = $this->sign( $uuid, $expires );
		$session = $this->sessions->save(
			new Session(
				id: null,
				uuid: $uuid,
				tokenHash: $this->hash( $token ),
				conversationId: $conversation->id,
				visitorId: $visitor?->id,
				transport: 'sse',
				ipHash: $this->ipHasher->hash(),
				expiresAt: $expires,
			)
		);

		/**
		 * Fires when a visitor opens a conversation.
		 *
		 * @param Conversation $conversation The new conversation.
		 * @param Agent        $agent        The clerk.
		 */
		do_action( 'hiveclerk/conversation/started', $conversation, $agent );

		return array(
			'token'        => $token,
			'session'      => $session,
			'conversation' => $conversation,
			'visitor'      => $visitor,
		);
	}

	/**
	 * Resolve a presented token to a live session.
	 *
	 * @param string $token Raw token from the X-HVC-Session header.
	 * @return Session|null Null when absent, malformed, forged or expired.
	 */
	public function resolve( string $token ): ?Session {
		if ( ! $this->verify( $token ) ) {
			return null;
		}

		$session = $this->sessions->findByTokenHash( $this->hash( $token ) );

		if ( null === $session ) {
			return null;
		}

		if ( $session->hasExpired( $this->clock->now() ) ) {
			return null;
		}

		return $session;
	}

	/**
	 * A short, stable, non-identifying key for rate limiting.
	 *
	 * The session uuid rather than the token: the bucket key ends up in a
	 * cache key and in a log line, and a credential does not belong in
	 * either.
	 *
	 * @param Session $session Session.
	 * @return string
	 */
	public function bucketKey( Session $session ): string {
		return $session->uuid->value;
	}

	/**
	 * Note which transport the widget settled on.
	 *
	 * @param Session $session   Session.
	 * @param string  $transport 'sse' or 'poll'.
	 * @return void
	 */
	public function recordTransport( Session $session, string $transport ): void {
		if ( null === $session->id || $session->transport === $transport ) {
			return;
		}

		$this->sessions->recordTransport( $session->id, $transport );

		$session->transport = $transport;
	}

	/**
	 * Build a signed token.
	 *
	 * @param Uuid              $uuid    Session identifier.
	 * @param DateTimeImmutable $expires Expiry.
	 * @return string
	 */
	private function sign( Uuid $uuid, DateTimeImmutable $expires ): string {
		$payload = $this->encode(
			(string) wp_json_encode(
				array(
					'v'   => 1,
					'sid' => $uuid->value,
					'exp' => $expires->getTimestamp(),
				)
			)
		);

		return self::PREFIX . $payload . '.' . $this->encode( $this->mac( $payload ) );
	}

	/**
	 * Whether a token was issued by this site and has not expired.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	private function verify( string $token ): bool {
		if ( ! str_starts_with( $token, self::PREFIX ) ) {
			return false;
		}

		$body  = substr( $token, strlen( self::PREFIX ) );
		$parts = explode( '.', $body );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		[ $payload, $signature ] = $parts;

		// Constant-time, because a timing-sensitive comparison here would
		// leak the signature one byte at a time to a patient caller.
		if ( ! hash_equals( $this->mac( $payload ), $this->decode( $signature ) ) ) {
			return false;
		}

		$claims = json_decode( $this->decode( $payload ), true );

		if ( ! is_array( $claims ) || 1 !== ( $claims['v'] ?? null ) ) {
			return false;
		}

		$expiry = $claims['exp'] ?? 0;

		return is_int( $expiry ) && $expiry > $this->clock->now()->getTimestamp();
	}

	/**
	 * The signature for a payload.
	 *
	 * Bound to the site's own address. A token lifted from one site is then
	 * inert on another, which matters on shared hosting where two installs
	 * can share a database prefix by accident.
	 *
	 * @param string $payload Encoded claims.
	 * @return string Raw binary digest.
	 */
	private function mac( string $payload ): string {
		return hash_hmac( 'sha256', $payload . '|' . get_site_url(), $this->secret(), true );
	}

	/**
	 * The signing secret, derived once per install.
	 *
	 * @return string
	 */
	private function secret(): string {
		$salt = get_option( self::SALT_OPTION );

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = bin2hex( random_bytes( 32 ) );

			// autoload off: this is read on public requests but not on every
			// page load, and an autoloaded option is loaded on both.
			add_option( self::SALT_OPTION, $salt, '', false );
		}

		$salts = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );

		// The per-install salt is the key material and the WordPress salts
		// are the HKDF salt, rather than the other way round. Both still
		// have to be stolen together — the first lives in an option, the
		// second in wp-config.php — but only one of them is guaranteed to
		// exist. hash_hkdf() throws on an empty key and tolerates an empty
		// salt, so an install with its constants missing or blanked gets a
		// weaker secret instead of a fatal error on every visitor message.
		return hash_hkdf( 'sha256', $salt, 32, 'hiveclerk-session-v1', $salts );
	}

	/**
	 * The stored form of a token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Reverse of encode().
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	private function decode( string $value ): string {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}

	/**
	 * The visitor's language, if the widget reported one.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string|null
	 */
	private function language( array $context ): ?string {
		$value = $context['language'] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return substr( trim( $value ), 0, 10 );
	}

	/**
	 * The page the conversation started on.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string|null
	 */
	private function pageUrl( array $context ): ?string {
		$value = $context['page_url'] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return substr( trim( $value ), 0, 500 );
	}
}
