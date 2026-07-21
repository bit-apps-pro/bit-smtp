<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Status;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Status\BrevoStatusChecker;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use RuntimeException;

/**
 * Drives BrevoStatusChecker::check() over a mocked ApiClient, proving it maps Brevo's
 * event feed to a DeliveryStatus and never throws into the caller.
 *
 * @internal
 *
 * @coversNothing
 */
class BrevoStatusCheckerTest extends BaseUnitTestCase
{
    private const EVENTS_URL = 'https://api.brevo.com/v3/smtp/statistics/events';

    private const MESSAGE_ID = 'msg-123';

    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(ApiClient::class);
    }

    public function testDeliveredEventMapsToDeliveredState(): void
    {
        $this->expectEventsRequest([['event' => 'delivered']]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('delivered', $status->state());
        $this->assertFalse($status->isNegative());
    }

    public function testDeferredEventCarriesReasonIntoDetail(): void
    {
        $this->expectEventsRequest([['event' => 'deferred', 'reason' => 'mailbox temporarily unavailable']]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('deferred', $status->state());
        $this->assertSame('mailbox temporarily unavailable', $status->detail());
        $this->assertTrue($status->isNegative());
    }

    public function testBlockedEventMapsToBlockedNegativeState(): void
    {
        $this->expectEventsRequest([['event' => 'blocked', 'reason' => 'unsubscribed address']]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('blocked', $status->state());
        $this->assertTrue($status->isNegative());
    }

    public function testWorstNegativeWinsWhenMultipleEventsPresent(): void
    {
        $this->expectEventsRequest([
            ['event' => 'delivered'],
            ['event' => 'blocked', 'reason' => 'spam complaint'],
        ]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('blocked', $status->state());
    }

    public function testDeliveredOutranksATransientDeferralAndIsNotFlippedToFailure(): void
    {
        $this->expectEventsRequest([
            ['event' => 'delivered'],
            ['event' => 'deferred', 'reason' => 'greylisted'],
        ]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('delivered', $status->state());
        $this->assertFalse($status->isNegative());
    }

    public function testHardNegativeStillDominatesADeferral(): void
    {
        $this->expectEventsRequest([
            ['event' => 'deferred', 'reason' => 'x'],
            ['event' => 'blocked', 'reason' => 'spam suspected'],
        ]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('blocked', $status->state());
    }

    public function testALoneDeferralRemainsDeferredWithItsReason(): void
    {
        $this->expectEventsRequest([['event' => 'deferred', 'reason' => 'greylisted']]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('deferred', $status->state());
        $this->assertSame('greylisted', $status->detail());
    }

    public function testEmptyEventsListMapsToAcceptedPending(): void
    {
        $this->expectEventsRequest([]);

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNotNull($status);
        $this->assertSame('accepted', $status->state());
        $this->assertSame('delivery status pending', $status->detail());
        $this->assertFalse($status->isNegative());
    }

    public function testNonSuccessResponseReturnsNull(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(['api-key' => 'server-token-123'])->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(401, ['message' => 'unauthorized']));

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNull($status);
    }

    public function testMissingApiKeyReturnsNullWithoutCallingTheClient(): void
    {
        $this->client->shouldNotReceive('setHeaders');
        $this->client->shouldNotReceive('get');

        $connection = $this->connection(['credentials' => []]);

        $this->assertNull($this->checker()->check(self::MESSAGE_ID, $connection));
    }

    public function testAnUnexpectedClientErrorIsSwallowedAndReturnsNull(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andThrow(new RuntimeException('boom'));

        $status = $this->checker()->check(self::MESSAGE_ID, $this->connection());

        $this->assertNull($status);
    }

    private function checker(): BrevoStatusChecker
    {
        return new BrevoStatusChecker($this->client);
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function expectEventsRequest(array $events): void
    {
        $this->client->shouldReceive('setHeaders')
            ->once()
            ->with(['api-key' => 'server-token-123'])
            ->andReturnSelf();
        $this->client->shouldReceive('get')
            ->once()
            ->with(self::EVENTS_URL, ['messageId' => self::MESSAGE_ID, 'limit' => 20])
            ->andReturn(new ApiResponse(200, ['events' => $events]));
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'          => 'conn_1',
            'provider'    => 'brevo',
            'kind'        => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'server-token-123']],
        ], $overrides));
    }
}
