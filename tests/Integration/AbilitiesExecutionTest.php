<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Analytics\RoutingExplainer;
use BitApps\SMTP\Model\Log;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class AbilitiesExecutionTest extends IntegrationTestCase
{
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

        $start       = time() - (3 * HOUR_IN_SECONDS);
        $this->range = [
            'start'  => gmdate('Y-m-d\\TH:i:s\\Z', $start),
            'end'    => gmdate('Y-m-d\\TH:i:s\\Z', $start + HOUR_IN_SECONDS),
            'bucket' => 'hour',
        ];
        $this->logId = $this->seedLog(gmdate('Y-m-d H:i:s', $start + 60));
        wp_set_current_user(1);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAuthorizedAdministratorsCanExecuteEveryAnalyticsAbilityWithoutExposingRawRecipientData(): void
    {
        $calls = [
            'bit-smtp/get-email-analytics'    => $this->range,
            'bit-smtp/analyze-plugin-email'   => array_merge($this->range, ['plugin' => 'woocommerce']),
            'bit-smtp/analyze-deliverability' => $this->range,
            'bit-smtp/explain-routing'        => ['log_id' => $this->logId],
            'bit-smtp/detect-email-anomalies' => $this->range,
        ];

        foreach ($calls as $name => $input) {
            $ability = wp_get_ability($name);
            self::assertNotNull($ability);

            $result = $ability->execute($input);

            self::assertNotInstanceOf(WP_Error::class, $result, "{$name} should execute successfully");
            self::assertIsArray($result);
            self::assertStringNotContainsString('customer@example.test', (string) wp_json_encode($result));
            self::assertStringNotContainsString('Raw receipt', (string) wp_json_encode($result));
        }
    }

    public function testUnauthorizedExecutionReturnsTheCoreStablePermissionError(): void
    {
        wp_set_current_user(0);
        $ability = wp_get_ability('bit-smtp/get-email-analytics');
        self::assertNotNull($ability);

        $result = $ability->execute($this->range);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ability_invalid_permissions', $result->get_error_code());
    }

    public function testMalformedInputIsRejectedBeforeTheServiceIsCalled(): void
    {
        $ability = wp_get_ability('bit-smtp/analyze-plugin-email');
        self::assertNotNull($ability);

        $result = $ability->execute(array_merge($this->range, [
            'plugin' => 'woocommerce not-a-plugin',
            'extra'  => 'must be rejected',
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ability_invalid_input', $result->get_error_code());
    }

    public function testRoutingInputRejectsMixedActualAndSimulationModes(): void
    {
        $ability = wp_get_ability('bit-smtp/explain-routing');
        self::assertNotNull($ability);

        $result = $ability->execute([
            'log_id'     => $this->logId,
            'to_domains' => ['customer.test'],
        ]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ability_invalid_input', $result->get_error_code());
    }

    public function testRoutingServiceFailuresKeepTheirStableBitSmtpErrorCode(): void
    {
        $ability = wp_get_ability('bit-smtp/explain-routing');
        self::assertNotNull($ability);

        $result = $ability->execute(['log_id' => 999999]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_log_not_found', $result->get_error_code());
    }

    public function testRoutingSimulationValidatesOutputForTheExistingMatchesRuleOperator(): void
    {
        $this->storeOptions([
            'enabled'               => true,
            'default_connection_id' => 'conn_default',
            'connections'           => [[
                'id'           => 'conn_default',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'enabled'      => true,
                'fromEmail'    => 'from@example.test',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [],
            ]],
            'features' => [
                'routing' => [[
                    'connectionId' => 'conn_default',
                    'conditions'   => [[
                        'field'    => 'source_plugin',
                        'operator' => 'matches',
                        'value'    => '^woo',
                    ]],
                ]],
            ],
        ]);
        $ability = wp_get_ability('bit-smtp/explain-routing');
        self::assertNotNull($ability);

        $result = $ability->execute([
            'to_domains'    => ['customer.test'],
            'source_plugin' => 'woocommerce',
        ]);

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('matches', $result['rules'][0]['conditions'][0]['operator']);
    }

    public function testRoutingExplainerItselfReturnsTheStableMissingLogError(): void
    {
        $result = (new RoutingExplainer())->actual(999999);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_log_not_found', $result->get_error_code());
    }

    private function seedLog(string $createdAt): int
    {
        global $wpdb;
        $table = (new Log())->getTable();
        $saved = $wpdb->insert(
            $table,
            [
                'status'             => 1,
                'subject'            => 'Raw receipt 883177 for customer@example.test',
                'to_addr'            => wp_json_encode(['customer@example.test']),
                'connection'         => 'conn_primary',
                'connection_id'      => 'conn_primary',
                'source_plugin'      => 'woocommerce',
                'routing_type'       => 'default',
                'routing_rule_index' => null,
                'delivery_status'    => 'delivered',
                'subject_pattern'    => 'Receipt <number>',
                'recipient_count'    => 1,
                'created_at'         => $createdAt,
                'updated_at'         => $createdAt,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']
        );

        self::assertSame(1, $saved);

        return (int) $wpdb->insert_id;
    }
}
