<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;

/**
 * Pins the delivery-status integration seam that the live test() path (Plugin singleton +
 * Capabilities + a real transport) keeps out of unit reach: the best-effort isolation guarantee
 * and the negative-outcome error flip. Uses a protected-method test subclass, mirroring
 * OAuthControllerTest, rather than reflection.
 *
 * @internal
 *
 * @coversNothing
 */
class ConnectionControllerTest extends BaseUnitTestCase
{
    private ExposedConnectionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        $this->controller = new ExposedConnectionController();
    }

    public function testAStatusLookupExceptionStillYieldsASuccessfulTest(): void
    {
        $checker = Mockery::mock(MessageStatusCheckerInterface::class);
        $checker->shouldReceive('check')->andThrow(new RuntimeException('boom'));

        $delivery = $this->controller->exposeResolveDelivery($checker, 'msg-1', $this->connection());
        $this->assertNull($delivery);

        $this->controller->exposeDeliveryResponse($this->sendResult(), $delivery);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertNull(((array) Response::getData())['delivery']);
    }

    public function testANegativeDeliveryFlipsTheResultToAnError(): void
    {
        $checker = Mockery::mock(MessageStatusCheckerInterface::class);
        $checker->shouldReceive('check')->andReturn(new DeliveryStatus('deferred', 'mailbox full'));

        $delivery = $this->controller->exposeResolveDelivery($checker, 'msg-1', $this->connection());
        $this->assertNotNull($delivery);

        $this->controller->exposeDeliveryResponse($this->sendResult(), $delivery);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame('deferred', $data['delivery']['state']);
        $this->assertSame('mailbox full', $data['delivery']['detail']);
    }

    public function testNoCheckerLeavesAnUnchangedSuccessWithNullDelivery(): void
    {
        $delivery = $this->controller->exposeResolveDelivery(null, null, $this->connection());
        $this->assertNull($delivery);

        $this->controller->exposeDeliveryResponse($this->sendResult(), $delivery);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertNull(((array) Response::getData())['delivery']);
    }

    private function sendResult(): SendResult
    {
        return SendResult::success(['status' => 201, 'body' => ['messageId' => 'msg-1']]);
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'brevo',
            'kind'        => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'server-token-123']],
        ]);
    }
}

/**
 * Exposes the protected delivery-status seam so the isolation guarantee and error flip are
 * unit-reachable without the live Plugin singleton / Capabilities / transport dependencies.
 *
 * @internal
 */
class ExposedConnectionController extends ConnectionController
{
    public function exposeResolveDelivery(?MessageStatusCheckerInterface $checker, ?string $messageId, Connection $connection): ?DeliveryStatus
    {
        return $this->resolveDelivery($checker, $messageId, $connection);
    }

    public function exposeDeliveryResponse(SendResult $result, ?DeliveryStatus $delivery): Response
    {
        return $this->deliveryResponse($result, $delivery);
    }
}
