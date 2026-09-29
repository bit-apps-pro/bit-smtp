<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\HTTP\Controllers\MailSettingsController;
use BitApps\SMTP\HTTP\Controllers\ProviderController;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\HTTP\Requests\MailSettingsSaveRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailSettingsControllerTest extends IntegrationTestCase
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

    public function testProviderIndexReturnsOtherSmtpInMetadata(): void
    {
        (new ProviderController())->index();
        $data = $this->responseData();

        $keys = array_column($data['providers'], 'key');
        $this->assertContains('other_smtp', $keys);
        $this->assertContains('php_sendmail', $keys);
    }

    public function testProviderIndexOtherSmtpFieldMetadataSupportsFormRendering(): void
    {
        (new ProviderController())->index();
        $data = $this->responseData();

        $otherSmtp = current(array_filter($data['providers'], static function (array $provider): bool {
            return $provider['key'] === 'other_smtp';
        }));
        $this->assertNotFalse($otherSmtp);

        $fieldsByKey = array_column($otherSmtp['fields'], null, 'key');

        $this->assertSame(
            [
                ['value' => 'tls', 'label' => 'STARTTLS (port 587, recommended)'],
                ['value' => 'ssl', 'label' => 'SSL/TLS (port 465)'],
                ['value' => 'none', 'label' => 'None (port 25, not encrypted)'],
            ],
            $fieldsByKey['encryption']['options']
        );
        $this->assertSame(
            ['field' => 'port', 'values' => ['tls' => 587, 'ssl' => 465, 'none' => 25]],
            $fieldsByKey['encryption']['autoSet']
        );
        $this->assertSame(['field' => 'auth', 'value' => true], $fieldsByKey['password']['requiredWhen']);
        $this->assertTrue($fieldsByKey['password']['secret']);
    }

    public function testSettingsIndexReturnsMaskedPassword(): void
    {
        $service = new MailConfigService();
        $service->saveSettings($this->v2Settings('conn_1', 'super-secret'));
        Plugin::instance()->mailConfigService()->reload();

        (new MailSettingsController())->index();
        $data = $this->responseData();

        $this->assertSame('********', $data['settings']['connections'][0]['credentials']['password']['value']);
    }

    public function testSettingsSaveWithSentinelPreservesStoredSecret(): void
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

    public function testSettingsIndexExposesTheAlertsRevisionAndDeliveryStatus(): void
    {
        (new MailConfigService())->saveSettings($this->withSlackAlerts($this->v2Settings('conn_1', 'pw')));
        Plugin::instance()->mailConfigService()->reload();

        (new MailSettingsController())->index();
        $alerts = $this->responseData()['settings']['features']['alerts'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $alerts['revision']);
        $this->assertStringNotContainsString('T00000000', $alerts['revision']);
        $this->assertSame(['cron_disabled' => \defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'overdue' => false], $alerts['delivery']);
        $this->assertSame('https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX', $alerts['channels'][0]['settings']['webhook_url']);
    }

    public function testAnUnrelatedSaveEchoingTheApiShapeKeepsTheAlertsRevision(): void
    {
        (new MailConfigService())->saveSettings($this->withSlackAlerts($this->v2Settings('conn_1', 'pw')));
        $api = (new MailConfigService())->apiSettings();

        $api['connections'][0]['name'] = 'Renamed';
        $this->saveThroughController($api);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $after = (new MailConfigService())->apiSettings();
        $this->assertSame($api['features']['alerts']['revision'], $after['features']['alerts']['revision']);
        $this->assertSame('Renamed', $after['connections'][0]['name']);
    }

    public function testAStaleAlertsRevisionIsRejectedWith409AndNothingIsStored(): void
    {
        (new MailConfigService())->saveSettings($this->withSlackAlerts($this->v2Settings('conn_1', 'pw')));
        $staleTab = (new MailConfigService())->apiSettings();

        $otherTab                                              = $staleTab;
        $otherTab['features']['alerts']['channels'][0]['name'] = 'Renamed elsewhere';
        $this->saveThroughController($otherTab);
        $this->assertSame(Response::SUCCESS, Response::getStatus());

        $staleTab['features']['alerts']['channels'][0]['name'] = 'Stale edit';
        $this->saveThroughController($staleTab);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame(409, Response::getHttpStatusCode());
        $this->assertSame('alerts_conflict', Response::getCode());
        $this->assertSame('Notification settings were changed in another tab. Reload to see the latest.', Response::getMessage());
        $this->assertSame('Renamed elsewhere', (new MailConfigService())->load()->getFeatures()['alerts']['channels'][0]['name']);
    }

    public function testTheRevisionAndDeliveryStatusAreNeverPersisted(): void
    {
        (new MailConfigService())->saveSettings($this->withSlackAlerts($this->v2Settings('conn_1', 'pw')));

        $this->saveThroughController((new MailConfigService())->apiSettings());

        $stored = Config::getOption('options');
        $this->assertSame(['enabled', 'reminder_minutes', 'channels'], array_keys($stored['features']['alerts']));
    }

    public function testAnEnabledIncompleteAlertChannelIsRejectedWith422(): void
    {
        $payload                                                                 = $this->withSlackAlerts($this->v2Settings('conn_1', 'pw'));
        $payload['features']['alerts']['channels'][0]['settings']['webhook_url'] = '';

        $this->saveThroughController($payload);

        $this->assertSame(422, Response::getHttpStatusCode());
        $this->assertSame('invalid_alert_channel', Response::getCode());
        $this->assertSame('Slack channel “Ops” needs a webhook URL.', Response::getMessage());
        $this->assertFalse(Config::getOption('options'));
    }

    public function testMailSettingsSaveRequestValidatedKeepsTheAlertsRevision(): void
    {
        $payload = ['features' => ['alerts' => ['enabled' => true, 'revision' => 'abc123', 'channels' => []]]];

        $request = new MailSettingsSaveRequest();
        $result  = $request->make($payload, $request->rules())->validated();

        $this->assertSame('abc123', $result['features']['alerts']['revision']);
    }

    public function testConnectionSaveAssignsIdAndSetsDefault(): void
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

    public function testConnectionSaveUnknownProviderReturnsError(): void
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

    public function testConnectionDeleteRemovesAndRepointsDefault(): void
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

    public function testConnectionTestDeliversToMailpit(): void
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

    public function testConnectionTestUnreachableHostReturnsError(): void
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

    public function testConnectionTestWithSentinelPasswordUsesStoredSecret(): void
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

    public function testAllSixNewRoutesAreRegistered(): void
    {
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }

        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();

        $prefix = '/' . \BitApps\SMTP\Config::SLUG . '/v' . \BitApps\SMTP\Config::API_VERSION;

        $expectedRoutes = [
            $prefix . '/mail/settings',
            $prefix . '/mail/settings/save',
            $prefix . '/mail/providers',
            $prefix . '/mail/connections/save',
            $prefix . '/mail/connections/webhook/create',
            $prefix . '/mail/connections/delete',
            $prefix . '/mail/connections/test',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertArrayHasKey($route, $routes, "Route not registered: {$route}");
        }
    }

    public function testConnectionSaveRequestValidatedPreservesSenderIdentityFields(): void
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

    public function testMailSettingsSaveRequestValidatedPreservesFeaturesAndFallbackIds(): void
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

    /**
     * Save a payload through the settings controller, as the REST route does.
     *
     * @param array<string,mixed> $payload
     */
    private function saveThroughController(array $payload): void
    {
        Response::reset();
        Plugin::instance()->mailConfigService()->reload();
        (new MailSettingsController())->save($this->mockRequest(MailSettingsSaveRequest::class, $payload));
    }

    /**
     * The given settings with alerts on and one enabled Slack channel named "Ops".
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    private function withSlackAlerts(array $settings): array
    {
        $settings['features']['alerts'] = [
            'enabled'  => true,
            'channels' => [[
                'id'       => 'ch_slackaa',
                'type'     => 'slack',
                'name'     => 'Ops',
                'enabled'  => true,
                'settings' => ['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX'],
            ]],
        ];

        return $settings;
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
