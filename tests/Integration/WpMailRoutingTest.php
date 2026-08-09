<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use Mockery;
use ReflectionClass;

/**
 * Exercises smart-routing in the live pre_wp_mail dispatch: a matching rule sends via its chosen
 * connection first, while a non-matching send keeps the ordinary default->fallback order (BC).
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
        $this->clearLogs();
    }

    public function testMatchingRoutingRuleSendsViaChosenConnectionFirst(): void
    {
        // Default is an unreachable SMTP host; connection B points at mailpit. The rule routes any
        // recipient on routed.test to B, so B must be tried FIRST and the unreachable default skipped.
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

        // Exactly one attempt means B (the routed connection) was first and succeeded outright; the
        // unreachable default was never tried (that would have produced a second, failed log row).
        $logs = $this->logs();
        $this->assertCount(1, $logs, 'the routed connection must be attempted first, skipping the default');
        $this->assertSame(Log::SUCCESS, $logs[0]->status);
        $this->assertSame('rule', $logs[0]->routing_type);
        $this->assertSame(0, $logs[0]->routing_rule_index);
    }

    public function testNonMatchingRecipientKeepsTheDefaultOrder(): void
    {
        // Same settings, but a recipient the rule does not match: routing yields nothing, so the
        // ordinary order runs — the unreachable default is tried first and fails, then the mailpit
        // connection delivers via the normal fallback tail. Proves no reroute for non-matching mail.
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
        $logs = Log::desc()->get();

        return $logs instanceof Collection ? $logs->all() : $logs;
    }

    private function clearLogs(): void
    {
        global $wpdb;
        $table = (new Log())->getTable();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    private function replaceSourceDetector(object $bridge, MailSourceDetector $detector): MailSourceDetector
    {
        $property = (new ReflectionClass($bridge))->getProperty('sourceDetector');
        $property->setAccessible(true);
        $previous = $property->getValue($bridge);
        $property->setValue($bridge, $detector);

        return $previous;
    }

    private function replaceLoggingEnabled(object $bridge, bool $enabled): bool
    {
        $property = (new ReflectionClass($bridge))->getProperty('loggingEnabled');
        $property->setAccessible(true);
        $previous = $property->getValue($bridge);
        $property->setValue($bridge, $enabled);

        return $previous;
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
