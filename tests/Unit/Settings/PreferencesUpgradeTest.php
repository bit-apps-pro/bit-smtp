<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Settings\PreferencesUpgrade;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class PreferencesUpgradeTest extends BaseUnitTestCase
{
    public function testALegacyEmptyRetryListBecomesTheExplicitListOfEveryRetryableClass(): void
    {
        $upgraded = PreferencesUpgrade::upgrade(['retry_on_classes' => [], 'notify_cooldown_minutes' => 0]);

        self::assertSame(FailureCategory::retryableClasses(), $upgraded['retry_on_classes']);
    }

    public function testAnEmptyRetryListInACurrentBlobStaysEmptyMeaningRetryNothing(): void
    {
        $upgraded = PreferencesUpgrade::upgrade(['retry_on_classes' => [], 'notify_events' => []]);

        self::assertSame([], $upgraded['retry_on_classes']);
    }

    public function testANonEmptyRetryListIsKeptAsChosen(): void
    {
        $upgraded = PreferencesUpgrade::upgrade(['retry_on_classes' => [FailureCategory::RATE_LIMITED]]);

        self::assertSame([FailureCategory::RATE_LIMITED], $upgraded['retry_on_classes']);
    }

    public function testALegacyBlobKeepsItsHealthEventsGainsSendFailuresAndDropsTheCooldown(): void
    {
        $upgraded = PreferencesUpgrade::upgrade([
            'notify_events'           => [HealthNotification::EVENT_OAUTH_EXPIRED, HealthNotification::EVENT_UNHEALTHY],
            'notify_cooldown_minutes' => 15,
        ]);

        self::assertSame(
            [AlertEvents::SEND_FAILURE, HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_OAUTH_EXPIRED],
            $upgraded['notify_events']
        );
        self::assertArrayNotHasKey(PreferencesUpgrade::LEGACY_COOLDOWN_KEY, $upgraded);
    }

    public function testALegacyBlobWithNoHealthEventsStillReceivesSendFailures(): void
    {
        $upgraded = PreferencesUpgrade::upgrade(['notify_events' => [], 'notify_cooldown_minutes' => 0]);

        self::assertSame([AlertEvents::SEND_FAILURE], $upgraded['notify_events']);
    }

    public function testACurrentBlobIsLeftUntouched(): void
    {
        $current = [
            'retry_on_classes'     => [FailureCategory::TRANSIENT],
            'notify_events'        => [HealthNotification::EVENT_RECOVERED],
            'send_timeout_seconds' => 30,
            'retry_max_attempts'   => 3,
        ];

        self::assertSame($current, PreferencesUpgrade::upgrade($current));
    }

    public function testUpgradingTwiceEqualsUpgradingOnce(): void
    {
        $once = PreferencesUpgrade::upgrade(['retry_on_classes' => [], 'notify_events' => [], 'notify_cooldown_minutes' => 5]);

        self::assertSame($once, PreferencesUpgrade::upgrade($once));
    }

    public function testOutOfRangeTimeoutAndAttemptsAreClamped(): void
    {
        $upgraded = PreferencesUpgrade::upgrade(['send_timeout_seconds' => 300, 'retry_max_attempts' => 255]);

        self::assertSame(120, $upgraded['send_timeout_seconds']);
        self::assertSame(10, $upgraded['retry_max_attempts']);
    }

    /**
     * @return array<string,array{0:int,1:int,2:int}>
     */
    public static function repeatMergeProvider(): array
    {
        return [
            "don't repeat stays off"        => [0, 120, 0],
            'no cooldown keeps reminder'    => [60, 0, 60],
            'longer cooldown wins'          => [60, 240, 240],
            'odd cooldown snaps up'         => [15, 45, 60],
            'cooldown past a day caps'      => [60, 5000, 1440],
            'longer reminder wins'          => [240, 30, 240],
        ];
    }

    #[DataProvider('repeatMergeProvider')]
    public function testMergedRepeatMinutes(int $reminder, int $cooldown, int $expected): void
    {
        self::assertSame($expected, PreferencesUpgrade::mergedRepeatMinutes($reminder, $cooldown));
    }
}
