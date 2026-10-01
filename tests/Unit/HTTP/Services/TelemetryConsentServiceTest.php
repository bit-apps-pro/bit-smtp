<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Report\Report;
use BitApps\SMTP\HTTP\Services\TelemetryConsentService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class TelemetryConsentServiceTest extends BaseUnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @var list<string>
     */
    private array $clearedHooks = [];

    private MockInterface $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->options      = [];
        $this->clearedHooks = [];
        $this->report       = Mockery::mock(Report::class);
        $this->stubOptionsApi();
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $this->clearedHooks[] = $hook;

            return 0;
        });
    }

    public function testFreshSiteIsUnansweredAndNotSharing(): void
    {
        $this->report->shouldReceive('isTrackingAllowed')->andReturn(false);

        self::assertSame(['answered' => false, 'allowed' => false], $this->service()->status());
    }

    public function testAllowOptsInThroughTheLibraryAndRemembersTheAnswer(): void
    {
        $this->report->shouldReceive('trackingOptIn')->once()->andReturnUsing(function (): void {
            $this->options['bit_smtp_allow_tracking'] = true;
        });
        $this->report->shouldReceive('isTrackingAllowed')->andReturnUsing(fn () => $this->options['bit_smtp_allow_tracking'] ?? false);

        $status = $this->service()->allow();

        self::assertSame(['answered' => true, 'allowed' => true], $status);
        self::assertTrue($this->options[TelemetryConsentService::ANSWERED_OPTION]);
    }

    public function testDeclineStoresTheAnswerLocallyWithoutCallingTheLibraryOptOut(): void
    {
        $this->options['bit_smtp_allow_tracking'] = true;
        $this->report->shouldNotReceive('trackingOptOut');
        $this->report->shouldNotReceive('trackingOptIn');
        $this->report->shouldReceive('isTrackingAllowed')->andReturnUsing(fn () => (bool) $this->options['bit_smtp_allow_tracking']);

        $status = $this->service()->decline();

        self::assertSame(['answered' => true, 'allowed' => false], $status);
        self::assertFalse($this->options['bit_smtp_allow_tracking']);
        self::assertTrue($this->options['bit_smtp_tracking_notice_dismissed']);
        self::assertTrue($this->options[TelemetryConsentService::ANSWERED_OPTION]);
        self::assertSame(['bit_smtp_send_tracking_event'], $this->clearedHooks);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function legacyAnswerMarkers(): array
    {
        return [
            'old popup answered'      => ['bit_smtp_old_version', '1.2.3'],
            'old popup skipped'       => ['bit_smtp_tracking_skipped', true],
            'admin notice dismissed'  => ['bit_smtp_tracking_notice_dismissed', true],
        ];
    }

    #[DataProvider('legacyAnswerMarkers')]
    public function testEarlierAnswersFromOlderVersionsCountAsAnswered(string $option, mixed $value): void
    {
        $this->options[$option] = $value;
        $this->report->shouldReceive('isTrackingAllowed')->andReturn(false);

        self::assertTrue($this->service()->status()['answered']);
    }

    public function testSharingSiteCountsAsAnsweredEvenWithoutTheFlag(): void
    {
        $this->report->shouldReceive('isTrackingAllowed')->andReturn('1');

        self::assertSame(['answered' => true, 'allowed' => true], $this->service()->status());
    }

    /**
     * Build the service over the mocked library report.
     */
    private function service(): TelemetryConsentService
    {
        return new TelemetryConsentService($this->report);
    }

    /**
     * Back get_option/update_option with the in-memory option store.
     */
    private function stubOptionsApi(): void
    {
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;

            return true;
        });
    }
}
