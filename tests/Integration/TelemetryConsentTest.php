<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Telemetry;
use BitApps\SMTP\HTTP\Services\TelemetryConsentService;
use BitApps\SMTP\Plugin;

/**
 * @internal
 *
 * @coversNothing
 */
final class TelemetryConsentTest extends IntegrationTestCase
{
    private const TELEMETRY_OPTIONS = [
        TelemetryConsentService::ANSWERED_OPTION,
        'bit_smtp_allow_tracking',
        'bit_smtp_tracking_notice_dismissed',
        'bit_smtp_tracking_skipped',
        'bit_smtp_tracking_last_sended_at',
        'bit_smtp_old_version',
    ];

    /**
     * @var list<string>
     */
    private array $outboundUrls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTelemetryState();
        $this->outboundUrls = [];
        add_filter('pre_http_request', [$this, 'captureOutboundRequest'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'captureOutboundRequest'], 10);
        $this->resetTelemetryState();
        parent::tearDown();
    }

    /**
     * Record and short-circuit every outbound HTTP call so no test data leaves the machine.
     *
     * @param mixed $preempt
     */
    public function captureOutboundRequest($preempt, array $args, string $url): array
    {
        $this->outboundUrls[] = $url;

        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }

    public function testFreshSiteHasNotAnswered(): void
    {
        self::assertSame(['answered' => false, 'allowed' => false], $this->service()->status());
    }

    public function testDeclinePersistsTheAnswerAndSendsNothing(): void
    {
        $status = $this->service()->decline();

        self::assertSame(['answered' => true, 'allowed' => false], $status);
        self::assertSame(['answered' => true, 'allowed' => false], $this->service()->status());
        self::assertSame([], $this->outboundUrls);
        self::assertFalse(wp_next_scheduled('bit_smtp_send_tracking_event'));
    }

    public function testAllowPersistsTheAnswerAndSchedulesTheWeeklyReport(): void
    {
        $status = $this->service()->allow();

        self::assertSame(['answered' => true, 'allowed' => true], $status);
        self::assertNotFalse(wp_next_scheduled('bit_smtp_send_tracking_event'));
        self::assertContains('https://wp-api.bitapps.pro/public/plugin-track-create', $this->outboundUrls);
    }

    public function testAnswerSurvivesPluginDeactivation(): void
    {
        $this->service()->decline();

        Telemetry::report()->deactivatePlugin();

        self::assertTrue($this->service()->status()['answered']);
    }

    public function testLegacyConsentHooksStayDisabledAfterReactivation(): void
    {
        $report = Telemetry::report();
        self::assertFalse(has_action('admin_init', [$report, 'handleTrackingOptInOptOut']));
        self::assertFalse(has_action('admin_notices', [$report, 'adminNotice']));
        $this->service()->decline();
        $report->deactivatePlugin();

        Plugin::instance()->initWPTelemetry();

        self::assertFalse(has_action('admin_init', [$report, 'handleTrackingOptInOptOut']));
        self::assertFalse(has_action('admin_notices', [$report, 'adminNotice']));
        self::assertTrue($this->service()->status()['answered']);
        self::assertSame([], $this->outboundUrls);
    }

    public function testRevokingAfterAllowTurnsSharingOffAndClearsTheSchedule(): void
    {
        $this->service()->allow();

        $status = $this->service()->decline();

        self::assertSame(['answered' => true, 'allowed' => false], $status);
        self::assertFalse(wp_next_scheduled('bit_smtp_send_tracking_event'));
    }

    public function testConsentRoutesAreRegisteredUnderTheAdminGuardedApi(): void
    {
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();
        $prefix = '/' . Config::SLUG . '/v' . Config::API_VERSION;

        self::assertArrayHasKey($prefix . '/telemetry/status', $routes);
        self::assertArrayHasKey($prefix . '/telemetry/consent', $routes);
        self::assertArrayNotHasKey($prefix . '/telemetry/popup-status', $routes);
    }

    /**
     * Build the service over the plugin's real telemetry report.
     */
    private function service(): TelemetryConsentService
    {
        return new TelemetryConsentService(Telemetry::report());
    }

    /**
     * Remove every stored telemetry answer and the weekly report schedule.
     */
    private function resetTelemetryState(): void
    {
        foreach (self::TELEMETRY_OPTIONS as $option) {
            delete_option($option);
        }
        wp_clear_scheduled_hook('bit_smtp_send_tracking_event');
    }
}
