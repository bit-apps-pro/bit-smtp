<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;
use Mockery;

/**
 * Proves validation is wired into ConnectionController::save()/test() ahead of persist/send, and
 * that the two stages validate differently: save() checks only user-entered required fields
 * (RequiredFieldsValidator), so OAuth onboarding is not deadlocked, while test() enforces full
 * send-readiness via the provider's own validator (Gmail refresh_token, SES region charset, ...).
 *
 * @internal
 *
 * @coversNothing
 */
final class ConnectionValidationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Plugin::instance()->mailConfigService()->reload();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSaveWithEmptyRequiredCredentialReturnsErrorAndDoesNotPersist(): void
    {
        $payload = $this->sendGridPayload('', '');
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);

        (new ConnectionController())->save($request);

        $this->assertResponseError();
        $this->assertArrayHasKey('errors', $this->responseData());
        $this->assertArrayHasKey('api_key', $this->responseData()['errors']);
        $this->assertSame([], (new MailConfigService())->load()->getConnections()->all());
    }

    public function testSaveWithMaskedSentinelAndStoredSecretIsValid(): void
    {
        $service = new MailConfigService();
        $service->saveSettings($this->sendGridV2Settings('conn_sg', 'real-secret-key'));
        Plugin::instance()->mailConfigService()->reload();

        $payload = $this->sendGridPayload('conn_sg', '********');
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);

        (new ConnectionController())->save($request);

        $this->assertResponseOk();

        $stored = (new MailConfigService())->load()->getConnections()->byId('conn_sg');
        $this->assertNotNull($stored);
        $this->assertSame('real-secret-key', $stored->getCredentials()['api_key']['value']);
    }

    public function testSaveWithMaskedSentinelAndNoStoredSecretIsInvalid(): void
    {
        $payload = $this->sendGridPayload('conn_new', '********');
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);

        (new ConnectionController())->save($request);

        $this->assertResponseError();
        $this->assertArrayHasKey('api_key', $this->responseData()['errors']);
        $this->assertSame([], (new MailConfigService())->load()->getConnections()->all());
    }

    public function testTestWithEmptyRequiredCredentialReturnsErrorWithoutSending(): void
    {
        $captured = [];
        $filter   = static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.sendgrid.com') === false) {
                return $preempt;
            }
            $captured['url'] = $url;

            return $preempt;
        };
        add_filter('pre_http_request', $filter, 10, 3);

        try {
            $payload           = $this->sendGridPayload('', '');
            $payload['to']     = 'recipient@example.org';
            $request           = $this->mockRequest(ConnectionTestRequest::class, $payload);
            wp_set_current_user(1);

            (new ConnectionController())->test($request);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertResponseError();
        $this->assertArrayHasKey('api_key', $this->responseData()['errors']);
        $this->assertEmpty($captured, 'validation must reject before the transport issues any request');
    }

    public function testGmailSaveWithoutRefreshTokenSucceedsAndPersists(): void
    {
        $payload = $this->gmailPayload('', 'gclient', 'gsecret');
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);

        (new ConnectionController())->save($request);

        $this->assertResponseOk();

        $stored = (new MailConfigService())->load()->getConnections()->first();
        $this->assertNotNull($stored, 'Gmail connection must persist before OAuth consent runs');
        $this->assertSame('gmail', $stored->getProvider());
        $this->assertSame('gclient', $stored->getSettings()['client_id']);
    }

    public function testSaveOfANewConnectionReturnsTheMintedIdInTheResponse(): void
    {
        $payload = $this->gmailPayload('', 'gclient', 'gsecret');
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);

        (new ConnectionController())->save($request);

        $this->assertResponseOk();
        $stored = (new MailConfigService())->load()->getConnections()->first();
        $this->assertNotNull($stored);
        $this->assertSame($stored->getId(), $this->responseData()['id']);
        $this->assertStringStartsWith('conn_', $this->responseData()['id']);
    }

    public function testGmailTestWithoutRefreshTokenReturnsSendReadinessError(): void
    {
        $payload       = $this->gmailPayload('', 'gclient', 'gsecret');
        $payload['to'] = 'recipient@example.org';
        $request       = $this->mockRequest(ConnectionTestRequest::class, $payload);
        wp_set_current_user(1);

        (new ConnectionController())->test($request);

        $this->assertResponseError();
        $this->assertArrayHasKey('refresh_token', $this->responseData()['errors']);
    }

    public function testSesTestWithInvalidRegionIsRejected(): void
    {
        $payload       = $this->sesPayload('', 'AKIAEXAMPLE', 'ses-secret', 'not a region!');
        $payload['to'] = 'recipient@example.org';
        $request       = $this->mockRequest(ConnectionTestRequest::class, $payload);
        wp_set_current_user(1);

        (new ConnectionController())->test($request);

        $this->assertResponseError();
        $this->assertArrayHasKey('region', $this->responseData()['errors']);
    }

    /**
     * @return array<string,mixed>
     */
    private function gmailPayload(string $id, string $clientId, string $clientSecret): array
    {
        return [
            'id'           => $id,
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['client_id' => $clientId],
            'credentials'  => ['client_secret' => ['source' => 'database', 'value' => $clientSecret]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sesPayload(string $id, string $accessKey, string $secretKey, string $region): array
    {
        return [
            'id'           => $id,
            'provider'     => 'amazon_ses',
            'kind'         => 'api',
            'name'         => 'SES',
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['access_key' => $accessKey, 'region' => $region],
            'credentials'  => ['secret_key' => ['source' => 'database', 'value' => $secretKey]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sendGridV2Settings(string $connId, string $apiKey): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $connId,
            'fallback_connection_ids' => [],
            'connections'             => [$this->sendGridPayload($connId, $apiKey)],
            'features'                => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sendGridPayload(string $id, string $apiKey): array
    {
        return [
            'id'           => $id,
            'provider'     => 'sendgrid',
            'kind'         => 'api',
            'name'         => 'SendGrid',
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => [],
            'credentials'  => ['api_key' => ['source' => 'database', 'value' => $apiKey]],
        ];
    }

    /**
     * @param class-string $class
     */
    private function mockRequest(string $class, array $validated): object
    {
        $mock = Mockery::mock($class);
        $mock->shouldReceive('validated')->andReturn($validated);

        return $mock;
    }

    /**
     * Controllers return the Response singleton; read its data via the static accessors.
     */
    private function responseData(): array
    {
        return (array) Response::getData();
    }

    private function assertResponseOk(): void
    {
        $this->assertSame(Response::SUCCESS, Response::getStatus(), 'Expected success response');
    }

    private function assertResponseError(): void
    {
        $this->assertSame(Response::ERROR, Response::getStatus(), 'Expected error response');
    }
}
