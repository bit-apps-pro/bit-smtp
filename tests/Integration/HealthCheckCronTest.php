<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Settings\PluginSettings;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

// The WP-bundled PHPMailer backs the SpyPhpMailer subclass below; load it before the class is declared.
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthCheckCronTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(ConnectionHealthStore::OPTION_NAME);
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(ConnectionHealthStore::OPTION_NAME);
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);
        parent::tearDown();
    }

    public function testTheHealthCheckHookIsRegistered(): void
    {
        // Scheduler::boot() (init:11) wires the job callback; the action registration proves the job
        // was registered even if this test runs after one that unscheduled the cron event.
        $this->assertNotFalse(has_action(Config::HEALTH_CHECK_HOOK));
    }

    public function testTheCallbackDoesNothingWhenHealthCheckIsDisabled(): void
    {
        $this->storeSmtpConnection('conn_smtp', self::SMTP_HOST, self::SMTP_PORT);

        // health_check_enabled defaults to false (preferences were cleared in setUp).
        do_action(Config::HEALTH_CHECK_HOOK);

        $this->assertSame([], (new ConnectionHealthStore())->all());
        $this->assertFalse(get_option(HealthProbeRunner::LAST_RUN_OPTION));
    }

    public function testTheIntervalGateSuppressesARunWhileTheWindowHasNotElapsed(): void
    {
        PluginSettings::make()->set('health_check_enabled', true)->set('health_check_interval', 'daily')->save();
        $this->storeSmtpConnection('conn_smtp', self::SMTP_HOST, self::SMTP_PORT);

        // A run only 1 hour ago is still inside the daily window, so the callback must not probe.
        update_option(HealthProbeRunner::LAST_RUN_OPTION, time() - HOUR_IN_SECONDS, false);
        do_action(Config::HEALTH_CHECK_HOOK);
        $this->assertSame([], (new ConnectionHealthStore())->all());

        // Once the marker is stale (window elapsed), the same callback runs the probe.
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);
        do_action(Config::HEALTH_CHECK_HOOK);
        $record = (new ConnectionHealthStore())->get('conn_smtp');
        $this->assertNotNull($record);
        $this->assertSame(HealthStatus::HEALTHY, $record->getStatus());
        $this->assertNotFalse(get_option(HealthProbeRunner::LAST_RUN_OPTION));
    }

    public function testAReachableSmtpConnectionProbesHealthy(): void
    {
        $this->storeSmtpConnection('conn_smtp', self::SMTP_HOST, self::SMTP_PORT);

        $this->runner()->run();

        $record = (new ConnectionHealthStore())->get('conn_smtp');
        $this->assertNotNull($record);
        $this->assertSame(HealthStatus::HEALTHY, $record->getStatus());
        $this->assertNotNull($record->getLastProbeAt());
    }

    public function testAnUnreachableSmtpHostProbesFailingAndTripsToUnhealthy(): void
    {
        // Port 2 is closed: the connect is refused immediately (no slow timeout).
        $this->storeSmtpConnection('conn_bad', '127.0.0.1', 2);

        $probe   = $this->probe();
        $result  = $probe->probe($this->connection('conn_bad', '127.0.0.1', 2));
        $this->assertFalse($result->isOk());
        $this->assertSame(FailureCategory::TRANSIENT, $result->getFailureClass());

        // A transient failure trips only at the threshold, so three probe runs are needed to open it.
        $runner = $this->runner();
        $runner->run();
        $runner->run();
        $runner->run();

        $record = (new ConnectionHealthStore())->get('conn_bad');
        $this->assertNotNull($record);
        $this->assertTrue($record->isUnhealthy());
        $this->assertSame(HealthStatus::CIRCUIT_OPEN, $record->getCircuit());
    }

    public function testTheSocketIsClosedOnASuccessfulProbe(): void
    {
        $spy    = new SpyPhpMailer();
        $result = (new SpyProbe($spy))->probe($this->connection('conn_smtp', self::SMTP_HOST, self::SMTP_PORT));

        $this->assertTrue($result->isOk());
        $this->assertTrue($spy->closed);
    }

    public function testTheSocketIsClosedEvenWhenTheProbeThrows(): void
    {
        $spy                 = new SpyPhpMailer();
        $spy->throwOnConnect = true;

        $result = (new SpyProbe($spy))->probe($this->connection('conn_bad', '127.0.0.1', 2));

        $this->assertFalse($result->isOk());
        $this->assertTrue($spy->closed);
    }

    public function testDeactivateClearsTheScheduledHook(): void
    {
        wp_schedule_event(time(), 'hourly', Config::HEALTH_CHECK_HOOK);
        $this->assertNotFalse(wp_next_scheduled(Config::HEALTH_CHECK_HOOK));

        (new InstallerProvider())->deactivate(false);

        $this->assertFalse(wp_next_scheduled(Config::HEALTH_CHECK_HOOK));
    }

    private function runner(): HealthProbeRunner
    {
        Plugin::instance()->mailConfigService()->reload();

        return Plugin::instance()->app()->make(HealthProbeRunner::class);
    }

    private function probe(): SmtpConnectionProbe
    {
        Plugin::instance()->mailConfigService()->reload();

        return Plugin::instance()->app()->make(SmtpConnectionProbe::class);
    }

    private function storeSmtpConnection(string $id, string $host, int $port): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $id,
            'fallback_connection_ids' => [],
            'connections'             => [$this->connection($id, $host, $port)->toArray()],
            'features'                => [],
        ]);
        Plugin::instance()->mailConfigService()->reload();
    }

    private function connection(string $id, string $host, int $port): Connection
    {
        return Connection::fromArray([
            'id'       => $id,
            'provider' => 'other_smtp',
            'kind'     => 'smtp',
            'name'     => $id,
            'enabled'  => true,
            'settings' => ['host' => $host, 'port' => $port, 'encryption' => 'none', 'auth' => false],
        ]);
    }
}

/**
 * PHPMailer double whose connect can be forced to fail and that records that smtpClose ran, so the
 * probe's always-close guarantee can be asserted without a real socket.
 */
final class SpyPhpMailer extends PHPMailer
{
    public bool $closed = false;

    public bool $throwOnConnect = false;

    public function smtpConnect($options = null)
    {
        if ($this->throwOnConnect) {
            throw new PHPMailerException('SMTP Error: Could not connect to SMTP host.');
        }

        return true;
    }

    public function smtpClose()
    {
        $this->closed = true;
    }
}

/**
 * SmtpConnectionProbe that drives an injected SpyPhpMailer instead of a live one.
 */
final class SpyProbe extends SmtpConnectionProbe
{
    private SpyPhpMailer $mailer;

    public function __construct(SpyPhpMailer $mailer)
    {
        parent::__construct(new SmtpTransport(new DatabaseCredentialResolver(), 5), new FailureClassifier());
        $this->mailer = $mailer;
    }

    protected function newMailer(): PHPMailer
    {
        return $this->mailer;
    }
}
