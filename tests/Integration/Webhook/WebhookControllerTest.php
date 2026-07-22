<?php

namespace BitApps\SMTP\Tests\Integration\Webhook;

use BitApps\SMTP\HTTP\Controllers\WebhookController;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;

/**
 * Drives WebhookController::handle() against the real test DB: authentication (unknown/disabled/wrong
 * secret), the brevo kill-switch, a correlated Postmark delivery (records a child row + marks the
 * connection verified), and the well-formed-but-uncorrelated post that must still return 200.
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

    public function testKillSwitchedBrevoProviderReturns404(): void
    {
        $this->saveApiConnection('conn_bv', 'brevo', self::KNOWN_SECRET, true);

        // Correct secret + enabled webhook, but the brevo adapter is kill-switched → no adapter → 404.
        $request = WebhookRequest::fromRaw(
            (string) wp_json_encode(['event' => 'delivered', 'message-id' => 'm1', 'email' => 'r@example.com']),
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(404, $this->controller()->handle('conn_bv', self::KNOWN_SECRET, $request));
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
                // name === id so label() equals the value createLog() stores on the log's
                // `connection` column, which is what delivery correlation scopes on.
                'name'         => $id,
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
    private function createLog(string $connection, string $messageId): int
    {
        $log              = new Log();
        $log->status      = Log::SUCCESS;
        $log->subject     = 'Subject';
        $log->to_addr     = ['recipient@example.com'];
        $log->connection  = $connection;
        $log->message_id  = $messageId;
        $log->tracking_id = null;
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
            (string) wp_json_encode($payload),
            ['Content-Type' => 'application/json']
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

        return \is_array($rows) ? $rows : [];
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
