<?php
/**
 * Licence activation and state.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\Encryptor;
use SensitiveParameter;

/**
 * Activates, deactivates and re-checks the licence (FR-SYS-01).
 *
 * ## Where the key lives
 *
 * Encrypted, in its own option, never in the settings blob. The settings
 * option is returned wholesale by `GET /admin/settings` and is read by
 * code that did not have a secret in mind when it was written; a licence
 * key in there is one careless `wp_json_encode( $settings )` away from a
 * debug log. {@see Licence} has nowhere to put it, so nothing that
 * renders a licence can leak one.
 *
 * ## Why the state is cached for twelve hours
 *
 * D6 §12. An entitlement check runs on every gated action, and a licence
 * server round trip inside a request that is trying to save a connector
 * is a request that hangs when our server is slow. Twelve hours is short
 * enough that a cancelled subscription stops working the same day and
 * long enough that a customer's site does not phone home on every page
 * load.
 *
 * The cached copy is in an option rather than a transient. A transient on
 * a site with no persistent object cache is a row in the same table with
 * an expiry we would then have to honour ourselves; and a transient on a
 * site *with* one is a licence state that vanishes the moment somebody
 * flushes Redis, silently downgrading a paying customer until the next
 * check.
 */
final class LicenceService {

	/**
	 * Where the encrypted key lives.
	 */
	private const KEY_OPTION = 'hiveclerk_licence_key';

	/**
	 * Where the last known state lives.
	 */
	private const STATE_OPTION = 'hiveclerk_licence_state';

	/**
	 * Seconds before the stored state is re-checked.
	 */
	public const REFRESH_AFTER = 43200;

	/**
	 * How long entitlements survive without a confirmed answer.
	 *
	 * Thirty days, and the number is a judgement rather than a
	 * measurement. It has to be long enough that no ordinary outage
	 * reaches it — our own downtime, a customer's firewall change, a
	 * certificate expiry, a host blocking outbound HTTPS over a holiday —
	 * and short enough that it is not a permanent bypass. At twelve-hourly
	 * checks it is sixty consecutive failures.
	 *
	 * The failure mode of making it too short is a paying customer losing
	 * features they paid for, which is worse than the failure mode of
	 * making it too long. It is set accordingly.
	 */
	public const GRACE_PERIOD = 2592000;

	/**
	 * Resolved licence for this request.
	 *
	 * @var Licence|null
	 */
	private ?Licence $cache = null;

	/**
	 * Construct.
	 *
	 * @param LicenceClient  $client    Licence API.
	 * @param Encryptor      $encryptor Secret storage.
	 * @param AuditLogger    $audit     Audit trail.
	 * @param ClockInterface $clock     Clock.
	 */
	public function __construct(
		private readonly LicenceClient $client,
		private readonly Encryptor $encryptor,
		private readonly AuditLogger $audit,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * The licence in force.
	 *
	 * Never calls the network. A stale state is refreshed by
	 * {@see self::refreshIfStale()}, which runs on `admin_init` where
	 * there is a request to be slow in and a next request to retry from.
	 *
	 * @return Licence
	 */
	public function current(): Licence {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			$this->cache = Licence::free();

			return $this->cache;
		}

		$licence = new Licence(
			Tier::fromStorage( is_string( $stored['tier'] ?? null ) ? $stored['tier'] : null ),
			LicenceStatus::fromStorage( is_string( $stored['status'] ?? null ) ? $stored['status'] : null ),
			is_string( $stored['masked'] ?? null ) ? $stored['masked'] : null,
			is_numeric( $stored['sites'] ?? null ) ? (int) $stored['sites'] : 1,
			LicenceClient::date( $stored['expires_at'] ?? null ),
			LicenceClient::date( $stored['checked_at'] ?? null ),
			is_string( $stored['customer'] ?? null ) ? $stored['customer'] : null,
			LicenceClient::date( $stored['confirmed_at'] ?? null )
		);

		$this->cache = $this->degradeIfUnconfirmed( $this->expireIfLapsed( $licence ) );

		return $this->cache;
	}

	/**
	 * Activate a key against this site.
	 *
	 * @param string $key Licence key as typed.
	 * @return Licence
	 */
	public function activate( #[SensitiveParameter] string $key ): Licence {
		$key = trim( $key );

		if ( '' === $key ) {
			return $this->store( Licence::free(), null );
		}

		$response = $this->client->activate( $key, $this->siteUrl() );

		if ( LicenceStatus::Unreachable === $response->status ) {
			// Nothing is stored. Writing an unreachable state here would
			// hand free entitlements to a key that may well be valid,
			// which is the wrong way round: an operator who has just
			// pasted a key wants to be told the check failed, not to be
			// silently activated on the strength of a timeout.
			return $this->current();
		}

		$licence = new Licence(
			$response->tier,
			$response->status,
			$this->encryptor->mask( $key ),
			max( 1, $response->sites ),
			$response->expiresAt,
			$this->now(),
			$response->customer,
			$this->now()
		);

		$stored = $this->store( $licence, $response->isActivation() ? $key : null );

		$this->audit->record(
			'licence.activated',
			array(
				'after' => array(
					'tier'   => $stored->tier->value,
					'status' => $stored->status->value,
				),
			),
			'licence'
		);

		return $stored;
	}

	/**
	 * Release this site's seat and fall back to free.
	 *
	 * The remote call is made first but its outcome is not allowed to
	 * block the local removal. An operator who has decided to stop using
	 * a key on this site must not be left holding it because our server
	 * was down — the seat is reconciled at the next check either way, and
	 * the alternative is a customer who cannot move their licence.
	 *
	 * @return Licence
	 */
	public function deactivate(): Licence {
		$key = $this->key();

		if ( null !== $key ) {
			$this->client->deactivate( $key, $this->siteUrl() );
		}

		$this->audit->record( 'licence.deactivated', array(), 'licence' );

		return $this->store( Licence::free(), null );
	}

	/**
	 * Re-check the stored key against the server.
	 *
	 * @return Licence
	 */
	public function recheck(): Licence {
		$key = $this->key();

		if ( null === $key ) {
			return $this->current();
		}

		$response = $this->client->check( $key, $this->siteUrl() );
		$current  = $this->current();

		if ( LicenceStatus::Unreachable === $response->status ) {
			// The tier is kept. Entitlements survive an outage, and the
			// screen shows "could not be checked" so the operator knows
			// what they are looking at.
			//
			// `confirmedAt` is carried forward untouched, not stamped with
			// now(). It records the last answer we could believe, and a
			// failed check is not one — stamping it here would reset the
			// grace period on every failure and the ceiling would never be
			// reached, which is the bug this field exists to prevent.
			return $this->store(
				new Licence(
					$current->tier,
					LicenceStatus::Unreachable,
					$current->masked,
					$current->sites,
					$current->expiresAt,
					$this->now(),
					$current->customer,
					$current->confirmedAt
				),
				$key
			);
		}

		return $this->store(
			new Licence(
				$response->tier,
				$response->status,
				$current->masked,
				max( 1, $response->sites ),
				$response->expiresAt,
				$this->now(),
				$response->customer,
				$this->now()
			),
			$response->isActivation() ? $key : null
		);
	}

	/**
	 * Re-check when the stored state has gone stale.
	 *
	 * Called from `admin_init`, not from a gate. A gate that made a
	 * network call would put our server's latency inside the customer's
	 * request every time they saved a connector.
	 *
	 * @return void
	 */
	public function refreshIfStale(): void {
		$licence = $this->current();

		if ( ! $licence->isPresent() ) {
			return;
		}

		$checked = $licence->checkedAt;

		if ( null !== $checked && $this->now()->getTimestamp() - $checked->getTimestamp() < self::REFRESH_AFTER ) {
			return;
		}

		$this->recheck();
	}

	/**
	 * Whether a licence key is stored at all.
	 *
	 * @return bool
	 */
	public function hasKey(): bool {
		return null !== $this->key();
	}

	/**
	 * Write the state, and the key alongside it or not at all.
	 *
	 * @param Licence     $licence State to store.
	 * @param string|null $key     Key to keep, or null to remove it.
	 * @return Licence
	 */
	private function store( Licence $licence, #[SensitiveParameter] ?string $key ): Licence {
		if ( null === $key ) {
			delete_option( self::KEY_OPTION );
		} else {
			update_option( self::KEY_OPTION, $this->encryptor->encrypt( $key ), false );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'tier'         => $licence->tier->value,
				'status'       => $licence->status->value,
				'masked'       => $licence->masked,
				'sites'        => $licence->sites,
				'expires_at'   => $licence->expiresAt?->format( 'c' ),
				'checked_at'   => $licence->checkedAt?->format( 'c' ),
				'customer'     => $licence->customer,
				'confirmed_at' => $licence->confirmedAt?->format( 'c' ),
			),
			false
		);

		$this->cache = $licence;

		/**
		 * Fires whenever the licence state changes.
		 *
		 * @param Licence $licence New state.
		 */
		do_action( 'hiveclerk/licence/changed', $licence );

		return $licence;
	}

	/**
	 * Downgrade a stored active licence whose expiry has passed.
	 *
	 * Applied on read rather than waiting for the next server check, so a
	 * licence that lapses between checks stops granting Pro features
	 * within the hour rather than within twelve. Note that this is the
	 * one direction it is safe to guess in: expiring locally can only
	 * ever cost us a sale, while extending locally would give away the
	 * product.
	 *
	 * @param Licence $licence Stored licence.
	 * @return Licence
	 */
	private function expireIfLapsed( Licence $licence ): Licence {
		if ( LicenceStatus::Active !== $licence->status || null === $licence->expiresAt ) {
			return $licence;
		}

		if ( $licence->expiresAt->getTimestamp() > $this->now()->getTimestamp() ) {
			return $licence;
		}

		return new Licence(
			$licence->tier,
			LicenceStatus::Expired,
			$licence->masked,
			$licence->sites,
			$licence->expiresAt,
			$licence->checkedAt,
			$licence->customer,
			$licence->confirmedAt
		);
	}

	/**
	 * Stop entitlements resting on an answer we have not had for a month.
	 *
	 * Applied on read rather than on write, like {@see self::expireIfLapsed()}
	 * and for the same reason: the boundary is a moment in time, not an
	 * event, so nothing fires when it is crossed. A site that stops being
	 * able to reach the server never runs any code at the instant its
	 * grace runs out.
	 *
	 * A licence with no confirmation on record is left alone. That is the
	 * state every install upgrading into this version is in — the
	 * timestamp did not exist before it — and degrading them all on the
	 * strength of a field that has never been written would take paid
	 * features away from every customer at once, which is precisely the
	 * accident this whole mechanism exists to make impossible. The anchor
	 * is set by the next successful check, within twelve hours.
	 *
	 * @param Licence $licence Stored licence.
	 * @return Licence
	 */
	private function degradeIfUnconfirmed( Licence $licence ): Licence {
		if ( LicenceStatus::Unreachable !== $licence->status ) {
			return $licence;
		}

		$elapsed = $licence->secondsSinceConfirmed( $this->now() );

		if ( null === $elapsed || $elapsed <= self::GRACE_PERIOD ) {
			return $licence;
		}

		return new Licence(
			$licence->tier,
			LicenceStatus::Unverified,
			$licence->masked,
			$licence->sites,
			$licence->expiresAt,
			$licence->checkedAt,
			$licence->customer,
			$licence->confirmedAt
		);
	}

	/**
	 * The stored key, decrypted.
	 *
	 * @return string|null
	 */
	private function key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$plain = $this->encryptor->decrypt( $stored );

		return is_string( $plain ) && '' !== $plain ? $plain : null;
	}

	/**
	 * The URL a seat is counted against.
	 *
	 * `home_url()` rather than `site_url()`: a WordPress install in a
	 * subdirectory serving a site at the root would otherwise take a
	 * different seat from the one the customer thinks they bought.
	 *
	 * @return string
	 */
	private function siteUrl(): string {
		return untrailingslashit( (string) home_url() );
	}

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
