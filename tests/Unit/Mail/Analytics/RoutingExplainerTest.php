<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Analytics\RoutingExplainer;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class RoutingExplainerTest extends BaseUnitTestCase
{
    public function testActualIdentifiesLegacyRowsWithoutInventingRoutingMetadata(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('get')->once()->with(41)->andReturn($this->log([
            'id'                 => 41,
            'connection_id'      => 'conn_default',
            'connection'         => 'Default',
            'source_plugin'      => null,
            'routing_type'       => null,
            'routing_rule_index' => null,
        ]));

        $result = (new RoutingExplainer($logs, $this->settings()))->actual(41);

        self::assertFalse($result['routing_metadata_available']);
        self::assertSame('unknown', $result['source_plugin']);
        self::assertNull($result['routing_type']);
        self::assertSame(['conn_fallback'], $result['fallback_chain']);
    }

    public function testActualReturnsThePersistedEnrichedDecision(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('get')->once()->with(42)->andReturn($this->log([
            'id'                 => 42,
            'connection_id'      => 'conn_rule',
            'connection'         => 'Rule',
            'source_plugin'      => 'woocommerce',
            'routing_type'       => 'rule',
            'routing_rule_index' => 0,
        ]));

        $result = (new RoutingExplainer($logs, $this->settings()))->actual(42);

        self::assertTrue($result['routing_metadata_available']);
        self::assertSame('woocommerce', $result['source_plugin']);
        self::assertSame('rule', $result['routing_type']);
        self::assertSame(0, $result['matched_rule_index']);
        self::assertSame('conn_rule', $result['selected_connection_id']);
    }

    public function testSimulationReportsEveryConditionAndUsesTheFirstMatchingRule(): void
    {
        $logs   = Mockery::mock(LogService::class);
        $result = (new RoutingExplainer($logs, $this->settings()))->simulate([
            'to_domains'    => ['customer.test'],
            'from'          => 'billing@example.test',
            'subject'       => 'Your order',
            'source_plugin' => 'woocommerce',
        ]);

        self::assertSame(0, $result['matched_rule_index']);
        self::assertSame('conn_rule', $result['matched_connection_id']);
        self::assertSame('conn_rule', $result['selected_connection_id']);
        self::assertTrue($result['rules'][0]['matches']);
        self::assertTrue($result['rules'][0]['conditions'][0]['matches']);
        self::assertSame(['conn_default', 'conn_fallback'], $result['fallback_candidates']);
    }

    public function testSimulationRejectsRecipientLocalParts(): void
    {
        $result = (new RoutingExplainer(Mockery::mock(LogService::class), $this->settings()))->simulate([
            'to_domains' => ['jane@example.test'],
        ]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_invalid_routing_simulation', $result->get_error_code());
    }

    private function settings(): MailSettings
    {
        return MailSettings::fromArray([
            'enabled'                 => true,
            'default_connection_id'   => 'conn_default',
            'fallback_connection_ids' => ['conn_fallback'],
            'connections'             => [
                $this->connection('conn_rule'),
                $this->connection('conn_default'),
                $this->connection('conn_fallback'),
            ],
            'features' => [
                'routing' => [
                    [
                        'connectionId' => 'conn_rule',
                        'conditions'   => [
                            ['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce'],
                            ['field' => 'recipient', 'operator' => 'domain', 'value' => 'customer.test'],
                        ],
                    ],
                    [
                        'connectionId' => 'conn_fallback',
                        'conditions'   => [
                            ['field' => 'from', 'operator' => 'domain', 'value' => 'example.test'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function connection(string $id): array
    {
        return [
            'id'           => $id,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'enabled'      => true,
            'fromEmail'    => 'from@example.test',
            'fromName'     => '',
            'replyToEmail' => '',
            'settings'     => [],
            'credentials'  => [],
        ];
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function log(array $attributes): Log
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        $log             = new Log();
        foreach ($attributes as $name => $value) {
            $log->{$name} = $value;
        }

        return $log;
    }
}
