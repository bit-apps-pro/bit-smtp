<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use Mockery;
use ReflectionClass;

/**
 * Tests smart-routing in dispatch: matching rules use chosen connection; non-matches use default/fallback.
 *
 * @internal
 *
 * @coversNothing
 */
final class WpMailRoutingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
    }

    public function testMatchingRoutingRuleSendsViaChosenConnectionFirst(): void
    {
        $this->storeV2(
            [
                $this->connection('conn_default', '127.0.0.1', 2),
                $this->connection('conn_mailpit', self::SMTP_HOST, self::SMTP_PORT),
            ],
            'conn_default',
            [],
            $this->routingFeature('conn_mailpit', 'recipient', 'domain', 'routed.test')
        );

        $sent = wp_mail('user@routed.test', 'Routed', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages(), 'the routed connection B should deliver to mailpit');
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());

        $logs = $this->logs();
        $this->assertCount(1, $logs, 'the routed connection must be attempted first, skipping the default');
        $this->assertSame(Log::SUCCESS, $logs[0]->status);
        $this->assertSame('rule', $logs[0]->routing_type);
        $this->assertSame(0, $logs[0]->routing_rule_index);
    }

    public function testNonMatchingRecipientKeepsTheDefaultOrder(): void
    {
        $this->storeV2(
            [
                $this->connection('conn_default', '127.0.0.1', 2),
                $this->connection('conn_mailpit', self::SMTP_HOST, self::SMTP_PORT),
            ],
            'conn_default',
            [],
            $this->routingFeature('conn_mailpit', 'recipient', 'domain', 'routed.test')
        );

        $sent = wp_mail('user@other.test', 'Unrouted', 'Body');

        $this->assertTrue($sent);
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());

        $logs = $this->logs();
        $this->assertCount(1, $logs, 'one row records the final outcome and the full default-order trail');
        $this->assertSame(Log::SUCCESS, $logs[0]->status);
        $this->assertSame('conn_mailpit', $logs[0]->connection);

        $attempts = $logs[0]->details['attempts'] ?? [];
        $this->assertCount(2, $attempts, 'the unreachable default must be tried before mailpit');
        $this->assertSame(['conn_default', 'failed'], [$attempts[0]['connection'], $attempts[0]['status']]);
        $this->assertSame(['conn_mailpit', 'sent'], [$attempts[1]['connection'], $attempts[1]['status']]);
    }

    public function testFallbackCapturesTheSourceOnceAndPersistsFallbackRouting(): void
    {
        $this->storeV2(
            [
                $this->connection('conn_default', '127.0.0.1', 2),
                $this->connection('conn_mailpit', self::SMTP_HOST, self::SMTP_PORT),
            ],
            'conn_default',
            [],
            $this->routingFeature('conn_mailpit', 'recipient', 'domain', 'routed.test')
        );

        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldReceive('detect')->once()->andReturn('woocommerce');
        $bridge   = Plugin::instance()->smtpProvider();
        $original = $this->replaceSourceDetector($bridge, $detector);

        try {
            $this->assertTrue(wp_mail('user@other.test', 'Fallback attribution', 'Body'));

            $logs = $this->logs();
            $this->assertCount(1, $logs);
            $this->assertSame('woocommerce', $logs[0]->source_plugin);
            $this->assertSame('fallback', $logs[0]->routing_type);
            $this->assertNull($logs[0]->routing_rule_index);
        } finally {
            $this->replaceSourceDetector($bridge, $original);
            Mockery::close();
        }
    }

    public function testDefaultSendCapturesTheSourceOnceAndPersistsDefaultRouting(): void
    {
        $this->storeV2(
            [$this->connection('conn_mailpit', self::SMTP_HOST, self::SMTP_PORT)],
            'conn_mailpit',
            [],
            []
        );

        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldReceive('detect')->once()->andReturn('woocommerce');
        $bridge   = Plugin::instance()->smtpProvider();
        $original = $this->replaceSourceDetector($bridge, $detector);

        try {
            $this->assertTrue(wp_mail('user@default.test', 'Default attribution', 'Body'));

            $logs = $this->logs();
            $this->assertCount(1, $logs);
            $this->assertSame('woocommerce', $logs[0]->source_plugin);
            $this->assertSame('default', $logs[0]->routing_type);
            $this->assertNull($logs[0]->routing_rule_index);
        } finally {
            $this->replaceSourceDetector($bridge, $original);
            Mockery::close();
        }
    }

    public function testDisabledSmtpAndLoggingDoNotDetectRetainedRoutingSource(): void
    {
        $this->useRealPhpMailer();
        $nativeMailpit = $this->configureNativeMailpit();
        $this->storeV2(
            [$this->connection('conn_default', self::SMTP_HOST, self::SMTP_PORT)],
            'conn_default',
            [],
            $this->routingFeature('conn_default', 'recipient', 'domain', 'routed.test'),
            false
        );

        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldNotReceive('detect');
        $bridge           = Plugin::instance()->smtpProvider();
        $originalDetector = $this->replaceSourceDetector($bridge, $detector);
        $originalLogging  = $this->replaceLoggingEnabled($bridge, false);

        try {
            $this->assertTrue(wp_mail('user@routed.test', 'Disabled SMTP', 'Body'));
            $this->assertNotEmpty($this->mailpitMessages());
            $this->assertCount(0, $this->logs());
        } finally {
            $this->replaceLoggingEnabled($bridge, $originalLogging);
            $this->replaceSourceDetector($bridge, $originalDetector);
            remove_action('phpmailer_init', $nativeMailpit);
            Mockery::close();
        }
    }

    public function testDisabledSmtpWithLoggingCapturesNativeSource(): void
    {
        $this->useRealPhpMailer();
        $nativeMailpit = $this->configureNativeMailpit();
        $this->storeV2(
            [$this->connection('conn_default', self::SMTP_HOST, self::SMTP_PORT)],
            'conn_default',
            [],
            $this->routingFeature('conn_default', 'recipient', 'domain', 'routed.test'),
            false
        );

        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldReceive('detect')->once()->andReturn('woocommerce');
        $bridge           = Plugin::instance()->smtpProvider();
        $originalDetector = $this->replaceSourceDetector($bridge, $detector);
        $originalLogging  = $this->replaceLoggingEnabled($bridge, true);

        try {
            $this->assertTrue(wp_mail('user@routed.test', 'Native attribution', 'Body'));
            $this->assertNotEmpty($this->mailpitMessages());

            $logs = $this->logs();
            $this->assertCount(1, $logs);
            $this->assertSame('woocommerce', $logs[0]->source_plugin);
            $this->assertSame('native', $logs[0]->routing_type);
        } finally {
            $this->replaceLoggingEnabled($bridge, $originalLogging);
            $this->replaceSourceDetector($bridge, $originalDetector);
            remove_action('phpmailer_init', $nativeMailpit);
            Mockery::close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function routingFeature(string $connectionId, string $field, string $operator, string $value): array
    {
        return [
            'routing' => [
                [
                    'connectionId' => $connectionId,
                    'conditions'   => [
                        ['field' => $field, 'operator' => $operator, 'value' => $value],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $connections
     * @param string[]                       $fallbackIds
     * @param array<string,mixed>            $features
     */
    private function storeV2(array $connections, string $defaultId, array $fallbackIds, array $features, bool $enabled = true): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => $enabled,
            'default_connection_id'   => $defaultId,
            'fallback_connection_ids' => $fallbackIds,
            'connections'             => $connections,
            'features'                => $features,
        ]);

        Plugin::instance()->mailConfigService()->reload();
    }

    /**
     * @return array<string,mixed>
     */
    private function connection(string $id, string $host, int $port): array
    {
        return [
            'id'           => $id,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => $id,
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => $host, 'port' => $port, 'encryption' => 'none', 'auth' => false],
            'credentials'  => [],
        ];
    }

    /**
     * @return Log[] newest first
     */
    private function logs(): array
    {
        return Log::desc()->get()->all();
    }

    /**
     * Swap the source detector on the bridge and its routing planner, returning the bridge's previous one.
     */
    private function replaceSourceDetector(object $bridge, MailSourceDetector $detector): MailSourceDetector
    {
        $planner  = (new ReflectionClass($bridge))->getProperty('routingPlanner')->getValue($bridge);
        $previous = $this->swapProperty($bridge, 'sourceDetector', $detector);
        $this->swapProperty($planner, 'sourceDetector', $detector);

        return $previous;
    }

    /**
     * Set a private property via reflection and return its prior value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function swapProperty(object $target, string $name, $value)
    {
        $property = (new ReflectionClass($target))->getProperty($name);
        $previous = $property->getValue($target);
        $property->setValue($target, $value);

        return $previous;
    }

    /**
     * Override the bridge's logging flag, returning its previous value.
     */
    private function replaceLoggingEnabled(object $bridge, bool $enabled): bool
    {
        return (bool) $this->swapProperty($bridge, 'loggingEnabled', $enabled);
    }

    private function configureNativeMailpit(): callable
    {
        $configure = static function ($mailer): void {
            $mailer->isSMTP();
            $mailer->Host        = self::SMTP_HOST;
            $mailer->Port        = self::SMTP_PORT;
            $mailer->SMTPAuth    = false;
            $mailer->SMTPAutoTLS = false;
            $mailer->SMTPSecure  = '';
        };
        add_action('phpmailer_init', $configure);

        return $configure;
    }
}
