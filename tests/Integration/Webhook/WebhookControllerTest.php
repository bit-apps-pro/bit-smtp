<?php

namespace BitApps\SMTP\Tests\Integration\Webhook;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\HTTP\Controllers\WebhookController;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;

/**
 * Drives WebhookController::handle() against the real test DB: authentication (unknown/disabled/wrong
 * secret), correlated SendGrid/Postmark events (record a child row + mark the connection verified),
 * and the well-formed-but-uncorrelated post that must still return 200.
 *
 * @internal
 *
 * @coversNothing
 */
final class WebhookControllerTest extends IntegrationTestCase
{
    private const KNOWN_SECRET = 'known-secret-abc123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->truncate((new LogDeliveryEvent())->getTable());
    }

    public function testUnknownConnectionReturns404(): void
    {
        $request = $this->postmarkDelivery('any-message-id');

        $this->assertSame(404, $this->controller()->handle('conn_missing', self::KNOWN_SECRET, $request));
    }

    public function testDisabledConnectionReturns404(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, false);

        $request = $this->postmarkDelivery('any-message-id');

        $this->assertSame(404, $this->controller()->handle('conn_pm', self::KNOWN_SECRET, $request));
    }

    public function testWrongSecretReturns404(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, true);

        $request = $this->postmarkDelivery('any-message-id');

        $this->assertSame(404, $this->controller()->handle('conn_pm', 'wrong-secret', $request));
    }

    public function testValidSendGridBlockedEventRecordsAndVerifies(): void
    {
        $this->saveApiConnection('conn_sg', 'sendgrid', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_sg', 'sg-response-id', 'track-1');

        $request = WebhookRequest::fromRaw(
            (string) wp_json_encode([[
                'event'           => 'bounce',
                'type'            => 'blocked',
                'email'           => 'recipient@example.com',
                'sg_message_id'   => 'sg-response-id.recvd-suffix',
                'bit_tracking_id' => 'track-1',
                'timestamp'       => 1704103201,
                'reason'          => '554 5.7.7 Email policy violation detected',
            ]])
        );

        $this->assertSame(200, $this->controller()->handle('conn_sg', self::KNOWN_SECRET, $request));
        $this->assertCount(1, $this->childRows($logId));
        $this->assertSame('blocked', $this->reloadLog($logId)->delivery_status);
        $this->assertSame(Log::SUCCESS, $this->reloadLog($logId)->status);

        $connection = (new MailConfigService())->connectionById('conn_sg');
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isWebhookVerified());
    }

    public function testValidPostmarkDeliveryRecordsAndVerifies(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_pm', 'pm-message-1');

        $status = $this->controller()->handle('conn_pm', self::KNOWN_SECRET, $this->postmarkDelivery('pm-message-1'));

        $this->assertSame(200, $status);
        $this->assertCount(1, $this->childRows($logId));
        $this->assertSame('delivered', $this->reloadLog($logId)->delivery_status);

        $connection = (new MailConfigService())->connectionById('conn_pm');
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isWebhookVerified());
    }

    public function testWellFormedButUncorrelatedPostStillReturns200(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, true);
        // A log exists, but its message-id does not match the incoming event, so nothing correlates.
        $logId = $this->createLog('conn_pm', 'a-different-id');

        $status = $this->controller()->handle('conn_pm', self::KNOWN_SECRET, $this->postmarkDelivery('unmatched-id'));

        $this->assertSame(200, $status);
        $this->assertCount(0, $this->childRows($logId));

        // Nothing correlated, so the connection must NOT be marked verified.
        $connection = (new MailConfigService())->connectionById('conn_pm');
        $this->assertNotNull($connection);
        $this->assertFalse($connection->isWebhookVerified());
    }

    public function testDeliveredWebhookUpgradesASendTimeAcceptedFloor(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_pm', 'pm-message-1', null, 'accepted');

        $status = $this->controller()->handle('conn_pm', self::KNOWN_SECRET, $this->postmarkDelivery('pm-message-1'));

        $this->assertSame(200, $status);
        $this->assertSame('delivered', $this->reloadLog($logId)->delivery_status);
    }

    public function testBouncedWebhookSupersedesASendTimeAcceptedFloor(): void
    {
        $this->saveApiConnection('conn_pm', 'postmark', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_pm', 'pm-message-1', null, 'accepted');

        $status = $this->controller()->handle('conn_pm', self::KNOWN_SECRET, $this->postmarkHardBounce('pm-message-1'));

        $this->assertSame(200, $status);
        $this->assertSame('bounced', $this->reloadLog($logId)->delivery_status);
    }

    private function controller(): WebhookController
    {
        return new WebhookController();
    }

    /**
     * Persist an API connection with a known webhook secret through the real config facade, so
     * connectionById()/markWebhookVerified() operate against genuinely stored state.
     */
    private function saveApiConnection(string $id, string $provider, string $secret, bool $webhookEnabled): void
    {
        (new MailConfigService())->saveSettings([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $id,
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => $id,
                'provider'     => $provider,
                'kind'         => 'api',
                'name'         => 'Connection ' . $id,
                'enabled'      => true,
                'fromEmail'    => 'from@example.com',
                'fromName'     => 'From',
                'replyToEmail' => '',
                'settings'     => [
                    'webhook_enabled' => $webhookEnabled,
                    'webhook_secret'  => $secret,
                ],
                'credentials'  => [],
            ]],
            'features' => [],
        ]);
    }

    /**
     * @return int inserted log id
     */
    private function createLog(string $connectionId, string $messageId, ?string $trackingId = null, ?string $deliveryStatus = null): int
    {
        $log                = new Log();
        $log->status        = Log::SUCCESS;
        $log->subject       = 'Subject';
        $log->to_addr       = ['recipient@example.com'];
        $log->connection    = 'Connection ' . $connectionId;
        $log->connection_id = $connectionId;
        $log->message_id    = $messageId;
        $log->tracking_id   = $trackingId;
        if ($deliveryStatus !== null) {
            $log->delivery_status     = $deliveryStatus;
            $log->delivery_updated_at = gmdate('Y-m-d H:i:s');
        }
        $log->save();

        return (int) $log->id;
    }

    private function postmarkDelivery(string $messageId): WebhookRequest
    {
        $payload = [
            'RecordType'  => 'Delivery',
            'MessageID'   => $messageId,
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-02-21T16:34:52Z',
            'Details'     => 'smtp;250 2.0.0 OK',
        ];

        return WebhookRequest::fromRaw(
            (string) wp_json_encode($payload)
        );
    }

    /**
     * Mirrors PostmarkWebhookAdapter::bounceEvent()'s hard-bounce detection (Type === HardBounce).
     */
    private function postmarkHardBounce(string $messageId): WebhookRequest
    {
        $payload = [
            'RecordType' => 'Bounce',
            'MessageID'  => $messageId,
            'Email'      => 'recipient@example.com',
            'Type'       => 'HardBounce',
            'TypeCode'   => 1,
            'BouncedAt'  => '2021-02-21T16:34:52Z',
        ];

        return WebhookRequest::fromRaw(
            (string) wp_json_encode($payload)
        );
    }

    private function reloadLog(int $logId): Log
    {
        return Log::where('id', $logId)->first();
    }

    /**
     * @return array<int,LogDeliveryEvent>
     */
    private function childRows(int $logId): array
    {
        $rows = LogDeliveryEvent::where('log_id', $logId)->get();

        if ($rows instanceof Collection) {
            return $rows->all();
        }

        return \is_array($rows) ? $rows : [];
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
