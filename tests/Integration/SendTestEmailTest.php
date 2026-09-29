<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\SMTPController;
use BitApps\SMTP\HTTP\Requests\MailTestRequest;
use BitApps\SMTP\Plugin;

/**
 * Verifies the Test page endpoint: default routing and a chosen connection both deliver and report the sender.
 *
 * @internal
 *
 * @coversNothing
 */
final class SendTestEmailTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useRealPhpMailer();
        $this->storeOptions([
            'status'             => true,
            'smtp_host'          => self::SMTP_HOST,
            'port'               => self::SMTP_PORT,
            'encryption'         => 'none',
            'smtp_auth'          => false,
            'from_email_address' => 'from@example.org',
            'from_name'          => 'From',
        ]);
        Plugin::instance()->mailConfigService()->reload();
        wp_set_current_user(1);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testDefaultRoutingDeliversAndReportsTheSendingConnection(): void
    {
        $this->send([]);

        $data = (array) Response::getData();
        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertNotNull($data['sent_via']);
        $this->assertFalse($data['failed_over']);
        $this->assertNotEmpty($this->mailpitMessages());
    }

    public function testAChosenConnectionDeliversThroughThatConnection(): void
    {
        $connection = Plugin::instance()->mailConfigService()->load()->getConnections()->first();
        $this->assertNotNull($connection);

        $this->send(['connection_id' => $connection->getId()]);

        $data = (array) Response::getData();
        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame($connection->label(), $data['sent_via']);
        $this->assertNotEmpty($this->mailpitMessages());
    }

    public function testAnUnknownConnectionIsRejectedWithoutSending(): void
    {
        $this->send(['connection_id' => 'does-not-exist']);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertEmpty($this->mailpitMessages());
    }

    /**
     * @param array<string,string> $extra
     */
    private function send(array $extra): void
    {
        $request = new MailTestRequest();
        $request->make(array_merge(['to' => 'to@example.org', 'subject' => 'Test page send'], $extra), $request->rules());
        (new SMTPController())->sendTestEmail($request);
    }
}
