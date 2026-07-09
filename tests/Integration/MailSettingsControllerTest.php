<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\HTTP\Controllers\MailSettingsController;
use BitApps\SMTP\HTTP\Controllers\ProviderController;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\HTTP\Requests\MailSettingsSaveRequest;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;
use Mockery;

final class MailSettingsControllerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Flush the Plugin singleton's cached MailConfigService state so each test starts clean.
        Plugin::instance()->mailConfigService()->reload();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // --- ProviderController ---

    public function test_provider_index_returns_other_smtp_in_metadata(): void
    {
        (new ProviderController())->index();
        $data = $this->responseData();

        $keys = array_column($data['providers'], 'key');
        $this->assertContains('other_smtp', $keys);
    }

    // --- MailSettingsController ---

    public function test_settings_index_returns_masked_password(): void
    {
        $service = new MailConfigService();
        $service->saveSettings($this->v2Settings('conn_1', 'super-secret'));
        Plugin::instance()->mailConfigService()->reload();

        (new MailSettingsController())->index();
        $data = $this->responseData();

        $this->assertSame('********', $data['settings']['connections'][0]['credentials']['password']['value']);
    }

    public function test_settings_save_with_sentinel_preserves_stored_secret(): void
    {
        $service = new MailConfigService();
        $service->saveSettings($this->v2Settings('conn_1', 'keep-me'));

        $request = $this->mockRequest(MailSettingsSaveRequest::class, $this->v2Settings('conn_1', '********'));
        Plugin::instance()->mailConfigService()->reload();

        (new MailSettingsController())->save($request);

        $stored = (new MailConfigService())->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($stored);
        $this->assertSame('keep-me', $stored->getCredentials()['password']['value']);
    }

    // --- ConnectionController::save ---

    public function test_connection_save_assigns_id_and_sets_default(): void
    {
        $payload = [
            'id'           => '',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'My Connection',
            'enabled'      => true,
            'fromEmail'    => 'from@example.com',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'pw1']],
        ];
        $request = $this->mockRequest(ConnectionSaveRequest::class, $payload);
        (new ConnectionController())->save($request);

        $this->assertResponseOk();

        $loaded = (new MailConfigService())->load();
        $conn   = $loaded->getConnections()->first();
        $this->assertNotNull($conn);
        $this->assertStringStartsWith('conn_', $conn->getId());
        $this->assertSame($conn->getId(), $loaded->getDefaultConnectionId());
    }

    public function test_connection_save_unknown_provider_returns_error(): void
    {
        $request  = $this->mockRequest(ConnectionSaveRequest::class, [
            'id'          => '',
            'provider'    => 'nonexistent_provider',
            'name'        => 'X',
            'enabled'     => true,
            'settings'    => [],
            'credentials' => [],
        ]);
        (new ConnectionController())->save($request);

        $this->assertResponseError();
        $this->assertSame([], (new MailConfigService())->load()->getConnections()->all());
    }

    // --- ConnectionController::delete ---

    public function test_connection_delete_removes_and_repoints_default(): void
    {
        $data                  = $this->v2Settings('conn_1', 'pass1');
        $data['connections'][] = [
            'id'           => 'conn_2',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Second',
            'enabled'      => true,
            'fromEmail'    => 'b@example.com',
            'fromName'     => 'B',
            'replyToEmail' => '',
            'settings'     => ['host' => 'smtp2.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'pass2']],
        ];
        (new MailConfigService())->saveSettings($data);
        Plugin::instance()->mailConfigService()->reload();

        $request = $this->mockRequest(ConnectionDeleteRequest::class, ['id' => 'conn_1']);
        (new ConnectionController())->delete($request);

        $this->assertResponseOk();

        $loaded = (new MailConfigService())->load();
        $this->assertNull($loaded->getConnections()->byId('conn_1'));
        $this->assertSame('conn_2', $loaded->getDefaultConnectionId());
    }

    // --- ConnectionController::test ---

    public function test_connection_test_delivers_to_mailpit(): void
    {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $payload = [
            'id'           => '',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Test',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.org',
            'fromName'     => 'Sender',
            'replyToEmail' => '',
            'settings'     => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => '']],
            'to'           => 'recipient@example.org',
        ];
        $request = $this->mockRequest(ConnectionTestRequest::class, $payload);

        wp_set_current_user(1);

        (new ConnectionController())->test($request);
        $this->assertResponseOk();
        $this->assertNotEmpty($this->mailpitMessages());

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered, 'No message found in mailpit');
        $this->assertSame('sender@example.org', $delivered['From']['Address'], 'From address must match configured fromEmail');
        $this->assertSame('recipient@example.org', $delivered['To'][0]['Address'], 'Recipient must match');
        $this->assertSame('Connection Test', $delivered['Subject'], 'Subject must match');
    }

    public function test_connection_test_unreachable_host_returns_error(): void
    {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $payload = [
            'id'           => '',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Test',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.org',
            'fromName'     => 'Sender',
            'replyToEmail' => '',
            'settings'     => ['host' => '127.0.0.1', 'port' => 2, 'encryption' => 'none', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => '']],
            'to'           => 'recipient@example.org',
        ];
        $request = $this->mockRequest(ConnectionTestRequest::class, $payload);
        wp_set_current_user(1);

        (new ConnectionController())->test($request);
        $this->assertResponseError();
    }

    public function test_connection_test_with_sentinel_password_uses_stored_secret(): void
    {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $service = new MailConfigService();
        $stored  = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_stored',
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => 'conn_stored',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Stored',
                'enabled'      => true,
                'fromEmail'    => 'sender@example.org',
                'fromName'     => 'Sender',
                'replyToEmail' => '',
                'settings'     => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => true, 'username' => 'anyuser', 'smtp_debug' => false],
                'credentials'  => ['password' => ['source' => 'database', 'value' => 'stored-pw']],
            ]],
            'features' => [],
        ];
        $service->saveSettings($stored);
        Plugin::instance()->mailConfigService()->reload();

        $payload = [
            'id'           => 'conn_stored',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Stored',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.org',
            'fromName'     => 'Sender',
            'replyToEmail' => '',
            'settings'     => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => true, 'username' => 'anyuser', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => '********']],
            'to'           => 'recipient@example.org',
        ];
        $request = $this->mockRequest(ConnectionTestRequest::class, $payload);
        wp_set_current_user(1);

        (new ConnectionController())->test($request);
        $this->assertResponseOk();
        $this->assertNotEmpty($this->mailpitMessages());
    }

    // --- Route registration smoke test ---

    public function test_all_six_new_routes_are_registered(): void
    {
        // loadApi() is gated on REST_REQUEST; define it to unlock route registration in this test.
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }

        // Re-fire rest_api_init so HookProvider::loadApi() runs and registers routes.
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();

        $prefix = '/' . \BitApps\SMTP\Config::SLUG . '/v' . \BitApps\SMTP\Config::API_VERSION;

        $expectedRoutes = [
            $prefix . '/mail/settings',
            $prefix . '/mail/settings/save',
            $prefix . '/mail/providers',
            $prefix . '/mail/connections/save',
            $prefix . '/mail/connections/delete',
            $prefix . '/mail/connections/test',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertArrayHasKey($route, $routes, "Route not registered: {$route}");
        }
    }

    // --- Request validation regression guards ---

    public function test_connection_save_request_validated_preserves_sender_identity_fields(): void
    {
        $payload = [
            'id'           => 'conn_1',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Test',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.com',
            'fromName'     => 'Sender',
            'replyToEmail' => 'reply@example.com',
            'settings'     => ['host' => 'smtp.example.com'],
            'credentials'  => [],
        ];

        $request = new ConnectionSaveRequest();
        $result  = $request->make($payload, $request->rules())->validated();

        $this->assertArrayHasKey('fromEmail', $result, 'validated() must not strip fromEmail');
        $this->assertArrayHasKey('fromName', $result, 'validated() must not strip fromName');
        $this->assertArrayHasKey('replyToEmail', $result, 'validated() must not strip replyToEmail');
        $this->assertSame('sender@example.com', $result['fromEmail']);
        $this->assertSame('Sender', $result['fromName']);
        $this->assertSame('reply@example.com', $result['replyToEmail']);
    }

    public function test_mail_settings_save_request_validated_preserves_features_and_fallback_ids(): void
    {
        $payload = [
            'enabled'                 => true,
            'default_connection_id'   => 'conn_1',
            'connections'             => [],
            'features'                => ['log_emails' => true],
            'fallback_connection_ids' => ['conn_2', 'conn_3'],
        ];

        $request = new MailSettingsSaveRequest();
        $result  = $request->make($payload, $request->rules())->validated();

        $this->assertArrayHasKey('features', $result, 'validated() must not strip features');
        $this->assertArrayHasKey('fallback_connection_ids', $result, 'validated() must not strip fallback_connection_ids');
        $this->assertSame(['log_emails' => true], $result['features']);
        $this->assertSame(['conn_2', 'conn_3'], $result['fallback_connection_ids']);
    }

    // --- Helpers ---

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

    private function v2Settings(string $connId, string $password): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $connId,
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => $connId,
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Primary',
                'enabled'      => true,
                'fromEmail'    => 'from@example.com',
                'fromName'     => 'From',
                'replyToEmail' => '',
                'settings'     => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'user', 'smtp_debug' => false],
                'credentials'  => ['password' => ['source' => 'database', 'value' => $password]],
            ]],
            'features' => [],
        ];
    }
}
