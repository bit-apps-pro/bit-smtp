<?php

namespace BitApps\SMTP\Tests\Integration\Webhook;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\HTTP\Controllers\WebhookController;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Aws\Sns\SnsSubscriptionConfirmer;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;
use OpenSSLAsymmetricKey;

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

    private const SNS_CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem';

    private OpenSSLAsymmetricKey $snsKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->truncate((new LogDeliveryEvent())->getTable());

        // Drive the REAL SnsSignatureVerifier: generate a keypair, pre-seed the cert cache the verifier
        // reads (so no network fetch), and sign each SNS envelope with the matching private key.
        $this->snsKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        set_transient('bit_smtp_sns_cert_' . md5(self::SNS_CERT_URL), openssl_pkey_get_details($this->snsKey)['key'], 300);
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

    public function testSesSnsDeliveryNotificationCorrelatesAndUpgradesTheAcceptedFloor(): void
    {
        $this->saveApiConnection('conn_ses', 'amazon_ses', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_ses', 'ses-msg-1', null, 'accepted');

        $status = (new WebhookController())->handle('conn_ses', self::KNOWN_SECRET, $this->snsSesDelivery('ses-msg-1'));

        $this->assertSame(200, $status);
        $this->assertCount(1, $this->childRows($logId));
        $this->assertSame('delivered', $this->reloadLog($logId)->delivery_status);
        $connection = (new MailConfigService())->connectionById('conn_ses');
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isWebhookVerified());
    }

    public function testSesSnsBounceSupersedesTheAcceptedFloor(): void
    {
        $this->saveApiConnection('conn_ses', 'amazon_ses', self::KNOWN_SECRET, true);
        $logId = $this->createLog('conn_ses', 'ses-msg-2', null, 'accepted');

        $status = (new WebhookController())->handle('conn_ses', self::KNOWN_SECRET, $this->snsSesBounce('ses-msg-2'));

        $this->assertSame(200, $status);
        $this->assertSame('bounced', $this->reloadLog($logId)->delivery_status);
    }

    public function testSesSnsForgedSignatureIsRejectedWith401(): void
    {
        $this->saveApiConnection('conn_ses', 'amazon_ses', self::KNOWN_SECRET, true);
        $this->createLog('conn_ses', 'ses-msg-3', null, 'accepted');

        // A well-formed SNS envelope with a bogus signature must not authenticate.
        $forged = WebhookRequest::fromRaw(
            (string) wp_json_encode($this->snsNotificationFields((string) wp_json_encode([
                'notificationType' => 'Delivery',
                'mail'             => ['messageId' => 'ses-msg-3'],
                'delivery'         => ['timestamp' => '2026-08-26T00:00:00Z', 'recipients' => ['recipient@example.com']],
            ])) + ['Signature' => base64_encode('not-a-real-signature'), 'SignatureVersion' => '1']),
            ['x-amz-sns-message-type' => 'Notification']
        );

        $this->assertSame(401, (new WebhookController())->handle('conn_ses', self::KNOWN_SECRET, $forged));
    }

    public function testSesSnsSubscriptionConfirmationIsConfirmedAndReturns200(): void
    {
        $this->saveApiConnection('conn_ses', 'amazon_ses', self::KNOWN_SECRET, true);
        $confirmer = new RecordingSnsConfirmer();

        $status = (new WebhookController(null, null, null, null, $confirmer))
            ->handle('conn_ses', self::KNOWN_SECRET, $this->snsSubscriptionConfirmation());

        $this->assertSame(200, $status);
        $this->assertTrue($confirmer->confirmed, 'a verified SubscriptionConfirmation must be acknowledged');
        // The confirming topic's AWS account (arn:aws:sns:us-east-1:1:ses) is pinned to the connection.
        $connection = (new MailConfigService())->connectionById('conn_ses');
        $this->assertSame('1', (string) $connection->setting('webhook_sns_account_id', ''));
    }

    public function testSesSnsNotificationFromADifferentAwsAccountIsRejectedWith403(): void
    {
        $this->saveApiConnection('conn_ses', 'amazon_ses', self::KNOWN_SECRET, true);
        // Pin the connection to account "1" via a confirmation, then deliver a notification from "999".
        (new WebhookController(null, null, null, null, new RecordingSnsConfirmer()))
            ->handle('conn_ses', self::KNOWN_SECRET, $this->snsSubscriptionConfirmation());
        $logId = $this->createLog('conn_ses', 'ses-msg-4', null, 'accepted');

        $foreign = $this->signedSnsNotification((string) wp_json_encode([
            'notificationType' => 'Delivery',
            'mail'             => ['messageId' => 'ses-msg-4'],
            'delivery'         => ['timestamp' => '2026-08-26T00:00:00Z', 'recipients' => ['recipient@example.com']],
        ]), 'arn:aws:sns:us-east-1:999:ses');

        $this->assertSame(403, (new WebhookController())->handle('conn_ses', self::KNOWN_SECRET, $foreign));
        $this->assertCount(0, $this->childRows($logId));
        $this->assertSame('accepted', $this->reloadLog($logId)->delivery_status);
    }

    private function controller(): WebhookController
    {
        return new WebhookController();
    }

    private function snsSesDelivery(string $sesMessageId): WebhookRequest
    {
        return $this->signedSnsNotification((string) wp_json_encode([
            'notificationType' => 'Delivery',
            'mail'             => ['messageId' => $sesMessageId],
            'delivery'         => ['timestamp' => '2026-08-26T00:00:00Z', 'recipients' => ['recipient@example.com']],
        ]));
    }

    private function snsSesBounce(string $sesMessageId): WebhookRequest
    {
        return $this->signedSnsNotification((string) wp_json_encode([
            'notificationType' => 'Bounce',
            'mail'             => ['messageId' => $sesMessageId],
            'bounce'           => [
                'bounceType'        => 'Permanent',
                'bounceSubType'     => 'General',
                'timestamp'         => '2026-08-26T00:00:00Z',
                'bouncedRecipients' => [['emailAddress' => 'recipient@example.com']],
            ],
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function snsNotificationFields(string $sesMessage, string $topicArn = 'arn:aws:sns:us-east-1:1:ses'): array
    {
        return [
            'Type'           => 'Notification',
            'MessageId'      => 'sns-1',
            'TopicArn'       => $topicArn,
            'Message'        => $sesMessage,
            'Timestamp'      => '2026-08-26T00:00:00.000Z',
            'SigningCertURL' => self::SNS_CERT_URL,
        ];
    }

    private function signedSnsNotification(string $sesMessage, string $topicArn = 'arn:aws:sns:us-east-1:1:ses'): WebhookRequest
    {
        $fields = $this->sign($this->snsNotificationFields($sesMessage, $topicArn));

        return WebhookRequest::fromRaw((string) wp_json_encode($fields), ['x-amz-sns-message-type' => 'Notification']);
    }

    private function snsSubscriptionConfirmation(): WebhookRequest
    {
        $fields = $this->sign([
            'Type'           => 'SubscriptionConfirmation',
            'MessageId'      => 'sns-sub-1',
            'Token'          => 'tok',
            'TopicArn'       => 'arn:aws:sns:us-east-1:1:ses',
            'Message'        => 'You have chosen to subscribe',
            'SubscribeURL'   => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=tok',
            'Timestamp'      => '2026-08-26T00:00:00.000Z',
            'SigningCertURL' => self::SNS_CERT_URL,
        ]);

        return WebhookRequest::fromRaw((string) wp_json_encode($fields), ['x-amz-sns-message-type' => 'SubscriptionConfirmation']);
    }

    /**
     * Sign an SNS envelope's canonical string-to-sign with the test key and append the Signature +
     * SignatureVersion, exactly as AWS SNS would.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function sign(array $fields): array
    {
        openssl_sign(\BitApps\SMTP\Mail\Aws\Sns\SnsMessage::fromArray($fields)->stringToSign(), $signature, $this->snsKey, \OPENSSL_ALGO_SHA1);
        $fields['Signature']        = base64_encode($signature);
        $fields['SignatureVersion'] = '1';

        return $fields;
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

/**
 * Records the confirm handshake instead of issuing the outbound GET, so the real SubscribeURL host
 * guard still runs but no network call is made.
 *
 * @internal
 */
class RecordingSnsConfirmer extends SnsSubscriptionConfirmer
{
    public bool $confirmed = false;

    protected function fetch(string $url): bool
    {
        $this->confirmed = true;

        return true;
    }
}
