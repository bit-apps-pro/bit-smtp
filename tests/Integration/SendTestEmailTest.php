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

    public function testTestEmailWithPlainWordsStillSendsAsHtml(): void
    {
        $this->send(['message' => 'Plain words, no tags']);

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);
        $this->assertStringContainsString('text/html', (string) ($this->mailpitHeaders($delivered['ID'])['Content-Type'][0] ?? ''));
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

    /**
     * @return array<string,array{0:string}>
     */
    public static function addressesFilterVarAcceptsButWordPressRejects(): array
    {
        return [
            'ip literal' => ['user@[127.0.0.1]'],
            'too short'  => ['a@b.c'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesFilterVarAcceptsButWordPressRejects')]
    public function testTheRequestRejectsAnAddressWordPressCannotSendTo(string $to): void
    {
        $request   = new MailTestRequest();
        $validator = $request->make(['to' => $to, 'subject' => 'S'], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('valid email address', (string) wp_json_encode($validator->errors()));
    }

    public function testRoutedTestSendToAnInvalidAddressExplainsTheFailure(): void
    {
        $this->sendUnvalidated(['to' => 'user@[127.0.0.1]', 'subject' => 'Bad to']);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertStringContainsString('No valid recipient address', (string) wp_json_encode(Response::getData()));
        $this->assertEmpty($this->mailpitMessages());
    }

    public function testChosenConnectionTestSendToAnInvalidAddressExplainsTheFailure(): void
    {
        $connection = Plugin::instance()->mailConfigService()->load()->getConnections()->first();

        $this->sendUnvalidated(['to' => 'user@[127.0.0.1]', 'subject' => 'Bad to', 'connection_id' => $connection->getId()]);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $data = (string) wp_json_encode(Response::getData());
        $this->assertStringContainsString('No valid recipient address', $data);
        $this->assertStringNotContainsString('Missing required field', $data);
    }

    public function testAnUnknownConnectionIsRejectedWithoutSending(): void
    {
        $this->send(['connection_id' => 'does-not-exist']);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertEmpty($this->mailpitMessages());
    }

    /**
     * Call the endpoint with data that skips the recipient rules, as a client posting past the form would.
     *
     * @param array<string,string> $data
     */
    private function sendUnvalidated(array $data): void
    {
        $request = new MailTestRequest();
        $request->make($data, ['to' => ['required', 'sanitize:text'], 'subject' => ['required', 'sanitize:text'], 'message' => ['nullable'], 'connection_id' => ['nullable', 'string']]);
        (new SMTPController())->sendTestEmail($request);
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
