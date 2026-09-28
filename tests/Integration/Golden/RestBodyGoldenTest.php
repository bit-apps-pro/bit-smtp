<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration\Golden;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionWebhookCreateRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Tests\Golden\Support\MatchesGolden;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;
use Mockery;

/**
 * Freezes end-to-end REST response shapes for SendGrid (must reproduce byte-for-byte).
 *
 * @internal
 *
 * @coversNothing
 */
final class RestBodyGoldenTest extends IntegrationTestCase
{
    use MatchesGolden;

    private const CONN_ID = 'conn_golden_1';

    private const WEBHOOK_SECRET = 'golden-webhook-secret-fixed-000000';

    private const API_KEY = 'SG.golden-api-key-1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        (new MailConfigService())->saveSettings($this->seedSettings());
        Plugin::instance()->mailConfigService()->reload();
        $this->resetResponseSingleton();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Freezes settings API shape (alerts revision masked as random per install).
     */
    public function testApiSettingsGolden(): void
    {
        $settings = (new MailConfigService())->apiSettings();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $settings['features']['alerts']['revision']);
        $settings['features']['alerts']['revision'] = '<revision>';

        $this->assertMatchesGolden($settings, 'rest_api_settings');
    }

    public function testProviderRegistryMetadataGolden(): void
    {
        $this->assertMatchesGolden(Plugin::instance()->providerRegistry()->metadata(), 'rest_provider_registry_metadata');
    }

    public function testConnectionSaveResponseGolden(): void
    {
        $payload                = $this->connectionPayload(['name' => 'Golden SendGrid Renamed']);
        $payload['credentials'] = ['api_key' => ['source' => 'database', 'value' => '********']];

        (new ConnectionController())->save($this->mockRequest(ConnectionSaveRequest::class, $payload));

        $this->assertMatchesGolden($this->restBody(), 'rest_connection_save_response');
    }

    public function testConnectionTestResponseGolden(): void
    {
        $filter = $this->interceptSendGridSend();
        add_filter('pre_http_request', $filter, 10, 3);
        wp_set_current_user(1);

        try {
            $payload                 = $this->connectionPayload();
            $payload['to']           = 'recipient@example.org';
            $payload['credentials']  = ['api_key' => ['source' => 'database', 'value' => '********']];

            (new ConnectionController())->test($this->mockRequest(ConnectionTestRequest::class, $payload));
        } finally {
            remove_filter('pre_http_request', $filter, 10);
            wp_set_current_user(0);
        }

        $this->assertMatchesGolden($this->restBody(), 'rest_connection_test_response');
    }

    /**
     * Forces an https home_url because SendGrid refuses to provision webhooks for non-HTTPS sites.
     */
    public function testConnectionCreateWebhookResponseGolden(): void
    {
        $homeUrlFilter = static function (string $url, string $path): string {
            return 'https://example.test' . $path;
        };
        $httpFilter = $this->interceptSendGridWebhookProvisioning();

        add_filter('home_url', $homeUrlFilter, 10, 2);
        add_filter('pre_http_request', $httpFilter, 10, 3);

        try {
            $request = $this->mockRequest(ConnectionWebhookCreateRequest::class, ['id' => self::CONN_ID]);
            (new ConnectionController())->createWebhook($request);
        } finally {
            remove_filter('pre_http_request', $httpFilter, 10);
            remove_filter('home_url', $homeUrlFilter, 10);
        }

        $this->assertMatchesGolden($this->restBody(), 'rest_connection_webhook_create_response');
    }

    /**
     * @return array<string,mixed>
     */
    private function seedSettings(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => self::CONN_ID,
            'fallback_connection_ids' => [],
            'connections'             => [$this->connectionPayload()],
            'features'                => [],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function connectionPayload(array $overrides = []): array
    {
        return array_replace([
            'id'           => self::CONN_ID,
            'provider'     => 'sendgrid',
            'kind'         => 'api',
            'name'         => 'Golden SendGrid',
            'enabled'      => true,
            'fromEmail'    => 'golden@example.org',
            'fromName'     => 'Golden Sender',
            'replyToEmail' => '',
            'settings'     => [
                'webhook_enabled' => true,
                'webhook_secret'  => self::WEBHOOK_SECRET,
            ],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => self::API_KEY]],
        ], $overrides);
    }

    private function interceptSendGridSend(): callable
    {
        return static function ($preempt, $args, $url) {
            if (strpos($url, 'api.sendgrid.com/v3/mail/send') === false) {
                return $preempt;
            }

            return [
                'headers'  => [],
                'body'     => '',
                'response' => ['code' => 202, 'message' => 'Accepted'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }

    private function interceptSendGridWebhookProvisioning(): callable
    {
        return function ($preempt, $args, $url) {
            if (strpos($url, 'api.sendgrid.com/v3/user/webhooks/event/settings') === false) {
                return $preempt;
            }

            $method = $args['method'] ?? 'GET';
            if ($method === 'GET') {
                $body   = wp_json_encode(['webhooks' => []]);
                $status = 200;
            } elseif ($method === 'POST') {
                $body = wp_json_encode([
                    'id'  => 'sg_wh_golden',
                    'url' => 'https://example.test/bit-smtp/' . self::CONN_ID . '/' . self::WEBHOOK_SECRET,
                ]);
                $status = 201;
            } else {
                $body   = wp_json_encode(['public_key' => 'GOLDEN_PUBLIC_KEY']);
                $status = 200;
            }

            return [
                'headers'  => ['content-type' => 'application/json'],
                'body'     => $body,
                'response' => ['code' => $status, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }

    /**
     * @param class-string        $class
     * @param array<string,mixed> $validated
     */
    private function mockRequest(string $class, array $validated): object
    {
        $mock = Mockery::mock($class);
        $mock->shouldReceive('validated')->andReturn($validated);

        return $mock;
    }

    /**
     * Reproduces WPKit RouteRegister::setResponse() wire shape (literal JSON from wp_send_json()).
     *
     * @return array<string,mixed>
     */
    private function restBody(): array
    {
        $body = ['status' => Response::getStatus()];
        if ($message = Response::getMessage()) {
            $body['message'] = $message;
        }
        if ($code = Response::getCode()) {
            $body['code'] = $code;
        }
        $body['data'] = Response::getData();

        return ['body' => $body, 'http_status' => Response::getHttpStatusCode()];
    }

    /**
     * Resets Response singleton before golden captures to isolate call state.
     */
    private function resetResponseSingleton(): void
    {
        Response::reset();
    }
}
