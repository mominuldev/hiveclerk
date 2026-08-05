<?php
/**
 * Which connectors a free licence may connect.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Domain\Integration\ConnectorDescriptor;
use Hiveclerk\Domain\Integration\CrmConnectorInterface;
use Hiveclerk\Modules\Integrations\Connectors\FluentCrmConnector;
use Hiveclerk\Modules\Integrations\Connectors\GroundhoggConnector;
use Hiveclerk\Modules\Integrations\Connectors\HubSpotConnector;
use Hiveclerk\Modules\Integrations\Connectors\SlackConnector;
use Hiveclerk\Modules\Integrations\Connectors\WebhookConnector;
use Hiveclerk\Modules\Integrations\Support\ConnectorHttp;
use Hiveclerk\Modules\Integrations\Support\WebhookSigner;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The free tier keeps the fallback.
 *
 * FR-CRM-09 calls the signed webhook the universal fallback, and its
 * whole point is that a CRM this product will never write an adapter for
 * is still reachable — through Zapier, Make, or twenty lines of PHP on
 * the customer's own server. Charging for the fallback takes that away
 * from exactly the users the free tier exists to win.
 *
 * `IntegrationController::connect()` reads `isPro` off the descriptor to
 * decide whether to consult the licence, so these flags are the contract
 * that keeps the fallback free. Read off the real connectors rather than
 * off descriptors this test builds, because a test that asserts the value
 * it just passed in proves nothing.
 *
 * @internal
 */
#[CoversClass( ConnectorDescriptor::class )]
final class ConnectorGatingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'__'            => static fn ( string $text ): string => $text,
				'esc_url_raw'   => static fn ( string $url ): string => $url,
				'apply_filters' => static fn ( string $hook, $value ) => $value,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function connectors(): array {
		return array(
			'the signed webhook is the universal fallback' => array( 'webhook', false ),
			'Slack is the other free notification route'   => array( 'slack', false ),
			'FluentCRM is a real CRM sync'                 => array( 'fluentcrm', true ),
			'Groundhogg is a real CRM sync'                => array( 'groundhogg', true ),
			'HubSpot is a real CRM sync'                   => array( 'hubspot', true ),
		);
	}

	#[DataProvider( 'connectors' )]
	public function testConnectorsDeclareWhetherTheyCostMoney( string $id, bool $expected ): void {
		$descriptor = $this->connector( $id )->descriptor();

		self::assertSame( $id, $descriptor->id );
		self::assertSame( $expected, $descriptor->isPro );
		// The grid's badge reads this off the wire; a descriptor that
		// dropped the flag would render "Pro" on everything.
		self::assertSame( $expected, $descriptor->toArray()['is_pro'] );
	}

	public function testAConnectorIsPaidUnlessItSaysOtherwise(): void {
		// The default is the safe direction: a connector added without
		// anybody thinking about tiers is gated rather than silently free.
		self::assertTrue( ( new ConnectorDescriptor( 'anything', 'Anything' ) )->isPro );
	}

	/**
	 * A real connector, wired with the least it needs to describe itself.
	 *
	 * @param string $id Connector id.
	 * @return CrmConnectorInterface
	 */
	private function connector( string $id ): CrmConnectorInterface {
		$clock = new FrozenClock( new DateTimeImmutable( '2026-08-05', new DateTimeZone( 'UTC' ) ) );
		$http  = new ConnectorHttp( new OutboundUrlGuard() );

		return match ( $id ) {
			'webhook'    => new WebhookConnector( $http, new WebhookSigner(), $clock ),
			'slack'      => new SlackConnector( $http ),
			'hubspot'    => new HubSpotConnector( $http, $clock ),
			'fluentcrm'  => new FluentCrmConnector(),
			'groundhogg' => new GroundhoggConnector(),
			default      => throw new \InvalidArgumentException( $id ),
		};
	}
}
