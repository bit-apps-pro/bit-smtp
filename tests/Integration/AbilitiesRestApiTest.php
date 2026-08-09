<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exercises Core's public Abilities REST transport, rather than provider callbacks directly.
 *
 * @internal
 *
 * @coversNothing
 */
final class AbilitiesRestApiTest extends IntegrationTestCase
{
    /**
     * @var array<int,string>
     */
    private const ABILITIES = [
        'bit-smtp/get-email-analytics',
        'bit-smtp/analyze-plugin-email',
        'bit-smtp/analyze-deliverability',
        'bit-smtp/explain-routing',
        'bit-smtp/detect-email-anomalies',
    ];

    private int $logId;

    /**
     * @var array{start:string,end:string,bucket:string}
     */
    private array $range;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . (new Log())->getTable());

        update_option('timezone_string', 'Asia/Dhaka');
        $this->storeOptions(['log_retention' => 200]);
        $this->range = [
            'start'  => '2026-08-01T00:00:00+06:00',
            'end'    => '2026-08-02T00:00:00+06:00',
            'bucket' => 'hour',
        ];
        $this->logId = $this->seedSensitiveLog();
        wp_set_current_user(1);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        delete_option('timezone_string');

        parent::tearDown();
    }

    public function testAuthenticatedAdministratorsDiscoverAllFiveAnalyticsAbilitiesThroughTheRestApi(): void
    {
        $response = $this->dispatch('GET', '/wp-abilities/v1/abilities', [
            'category' => 'bit-smtp-analytics',
            'per_page' => 10,
        ]);

        self::assertSame(200, $response->get_status());
        $items = $response->get_data();
        self::assertIsArray($items);
        self::assertSame(self::ABILITIES, array_values(array_column($items, 'name')));

        foreach ($items as $item) {
            self::assertSame('bit-smtp-analytics', $item['category']);
            self::assertSame([
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ], $item['meta']['annotations']);
            self::assertTrue($item['meta']['show_in_rest']);
        }
    }

    public function testAdministratorsExecuteEveryAnalyticsAbilityThroughItsRestRunRouteWithoutRawPersonalData(): void
    {
        $calls = [
            'bit-smtp/get-email-analytics'    => $this->range,
            'bit-smtp/analyze-plugin-email'   => array_merge($this->range, ['plugin' => 'woocommerce']),
            'bit-smtp/analyze-deliverability' => $this->range,
            'bit-smtp/explain-routing'        => ['log_id' => $this->logId],
            'bit-smtp/detect-email-anomalies' => $this->range,
        ];

        foreach ($calls as $ability => $input) {
            $response = $this->runAbility($ability, $input);

            self::assertSame(200, $response->get_status(), "{$ability} should execute through REST");
            $data = $response->get_data();
            self::assertIsArray($data);
            $this->assertAggregateOnlyResponse($data);
        }
    }

    public function testUnauthenticatedAndNonAdministratorRestRequestsCannotDiscoverOrExecuteAnalytics(): void
    {
        wp_set_current_user(0);
        $anonymousDiscovery = $this->dispatch('GET', '/wp-abilities/v1/abilities', ['category' => 'bit-smtp-analytics']);
        self::assertSame(401, $anonymousDiscovery->get_status());
        self::assertSame('rest_forbidden', $anonymousDiscovery->get_data()['code']);

        $anonymousRun = $this->runAbility('bit-smtp/get-email-analytics', $this->range);
        self::assertSame(401, $anonymousRun->get_status());
        self::assertSame('rest_ability_cannot_execute', $anonymousRun->get_data()['code']);

        $identifier = 'abilities-rest-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
        $subscriber = wp_insert_user([
            'user_login' => $identifier,
            'user_pass'  => 'not-used-by-this-test',
            'user_email' => $identifier . '@example.test',
            'role'       => 'subscriber',
        ]);
        self::assertIsInt($subscriber);
        wp_set_current_user($subscriber);
        $forbidden = $this->runAbility('bit-smtp/get-email-analytics', $this->range);
        self::assertSame(403, $forbidden->get_status());
        self::assertSame('rest_ability_cannot_execute', $forbidden->get_data()['code']);
    }

    public function testRestInputValidationAndServiceErrorsKeepStableCodes(): void
    {
        $invalidPlugin = $this->runAbility('bit-smtp/analyze-plugin-email', array_merge($this->range, [
            'plugin' => 'woocommerce invalid',
            'extra'  => 'unexpected',
        ]));
        self::assertSame(400, $invalidPlugin->get_status());
        self::assertSame('ability_invalid_input', $invalidPlugin->get_data()['code']);

        $invalidRange = $this->runAbility('bit-smtp/get-email-analytics', [
            'start' => '2026-08-02T00:00:00+06:00',
            'end'   => '2026-08-01T00:00:00+06:00',
        ]);
        self::assertSame(500, $invalidRange->get_status());
        self::assertSame('bit_smtp_invalid_analytics_range', $invalidRange->get_data()['code']);

        $invalidRoutingMode = $this->runAbility('bit-smtp/explain-routing', [
            'log_id'     => $this->logId,
            'to_domains' => ['example.test'],
        ]);
        self::assertSame(400, $invalidRoutingMode->get_status());
        self::assertSame('ability_invalid_input', $invalidRoutingMode->get_data()['code']);

        $missingLog = $this->runAbility('bit-smtp/explain-routing', ['log_id' => 999999]);
        self::assertSame(500, $missingLog->get_status());
        self::assertSame('bit_smtp_log_not_found', $missingLog->get_data()['code']);

        $logs = new LogService();
        self::assertTrue($logs->setEnabled(false));

        try {
            $disabled = $this->runAbility('bit-smtp/get-email-analytics', $this->range);
            self::assertSame(500, $disabled->get_status());
            self::assertSame('bit_smtp_logging_disabled', $disabled->get_data()['code']);
        } finally {
            $logs->setEnabled(true);
        }
    }

    /**
     * @param array<string,mixed> $input
     */
    private function runAbility(string $ability, array $input): WP_REST_Response
    {
        return $this->dispatch('GET', '/wp-abilities/v1/abilities/' . $ability . '/run', ['input' => $input]);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch(string $method, string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_query_params($params);

        return rest_get_server()->dispatch($request);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function assertAggregateOnlyResponse(array $response): void
    {
        $json = (string) wp_json_encode($response);
        self::assertStringNotContainsString('private.customer@example.test', $json);
        self::assertStringNotContainsString('Private receipt 884422', $json);
        self::assertStringNotContainsString('body never leaves the retained log', $json);
        self::assertStringNotContainsString('secret-token-value', $json);

        $keys = [];
        $this->collectKeys($response, $keys);
        // Aggregate count metadata such as recipients and unknown_recipient_count is allowed;
        // this list deliberately covers only keys that could carry a raw personal-data value.
        foreach (['to', 'to_addr', 'recipient', 'subject', 'body', 'details', 'debug_info', 'credentials', 'token'] as $key) {
            self::assertNotContains($key, $keys, "Response must not expose raw {$key} data");
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     */
    private function collectKeys(array $data, array &$keys): void
    {
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (\is_array($value)) {
                $this->collectKeys($value, $keys);
            }
        }
    }

    private function seedSensitiveLog(): int
    {
        global $wpdb;
        $createdAt = '2026-08-01 06:10:00';
        $inserted  = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'             => 1,
                'subject'            => 'Private receipt 884422 for private.customer@example.test',
                'to_addr'            => wp_json_encode(['private.customer@example.test']),
                'details'            => 'body never leaves the retained log',
                'debug_info'         => 'secret-token-value',
                'connection'         => 'conn_primary',
                'connection_id'      => 'conn_primary',
                'source_plugin'      => 'woocommerce',
                'routing_type'       => 'default',
                'routing_rule_index' => null,
                'delivery_status'    => 'delivered',
                'subject_pattern'    => 'Receipt <number>',
                'recipient_count'    => 1,
                'created_at'         => $createdAt,
                'created_at_utc'     => $createdAt,
                'updated_at'         => $createdAt,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        self::assertSame(1, $inserted);

        return (int) $wpdb->insert_id;
    }
}
