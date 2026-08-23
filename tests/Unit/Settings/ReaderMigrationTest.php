<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use WP_Error;

/**
 * Proves Task 14b: logging/retention readers source the seeded bit_smtp_preferences blob when it
 * exists, and fall back to the pre-migration legacy options for the window before
 * BitSmtpSettingsSeed (manage_options-gated) has run.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReaderMigrationTest extends BaseUnitTestCase
{
    public function testLogServiceIsEnabledReadsFromTheSeededPreferencesBlob(): void
    {
        $this->stubOptionsApi([
            PluginSettings::OPTION_NAME => ['logging_enabled' => false, 'log_retention_days' => 7],
            // A stale legacy value proves the preferences blob wins once seeded, not just a matching default.
            'bit_smtp_logging_enabled' => 1,
        ]);

        self::assertFalse((new LogService())->isEnabled());
    }

    public function testLogServiceIsEnabledFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $this->stubOptionsApi([
            'bit_smtp_logging_enabled' => 0,
        ]);

        self::assertFalse((new LogService())->isEnabled());
    }

    public function testAnalyticsQueryFactoryRetentionReadsFromTheSeededPreferencesBlob(): void
    {
        $this->stubOptionsApi([
            PluginSettings::OPTION_NAME => ['log_retention_days' => 7],
            // A stale legacy value proves the preferences blob wins once seeded.
            'bit_smtp_log_retention' => 45,
        ]);

        $query = $this->factoryWithConfiguredRetention()->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('2026-03-08T00:00:00+00:00', $query->start()->format(DATE_ATOM));
    }

    public function testAnalyticsQueryFactoryRetentionFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $this->stubOptionsApi([
            'bit_smtp_log_retention' => 7,
        ]);

        $query = $this->factoryWithConfiguredRetention()->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('2026-03-08T00:00:00+00:00', $query->start()->format(DATE_ATOM));
    }

    public function testMailAnalyticsServiceLoggingEnabledReadsFromTheSeededPreferencesBlob(): void
    {
        $this->stubOptionsApi([
            PluginSettings::OPTION_NAME => ['logging_enabled' => false],
            // A stale legacy value proves the preferences blob wins once seeded.
            'bit_smtp_logging_enabled' => 1,
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');

        $result = (new MailAnalyticsService($repo))->overview($this->analyticsQuery());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
    }

    public function testMailAnalyticsServiceLoggingEnabledFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $this->stubOptionsApi([
            'bit_smtp_logging_enabled' => 0,
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');

        $result = (new MailAnalyticsService($repo))->overview($this->analyticsQuery());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
    }

    /**
     * A factory whose constructor is never given an explicit retention, forcing it to resolve the
     * configured value the same way production code does (`new AnalyticsQueryFactory()`).
     */
    private function factoryWithConfiguredRetention(): AnalyticsQueryFactory
    {
        return new AnalyticsQueryFactory(new DateTimeImmutable('2026-03-15T00:00:00+00:00'), new DateTimeZone('UTC'));
    }

    private function analyticsQuery(): AnalyticsQuery
    {
        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-15T00:00:00+00:00'),
            new DateTimeZone('UTC'),
            200
        ))->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);

        return $query;
    }

    /**
     * Stub get_option/update_option/delete_option against an in-memory map keyed by option name.
     *
     * @param array<string,mixed> $options
     */
    private function stubOptionsApi(array $options): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use (&$options) {
            return $options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options): bool {
            $options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(static function (string $key) use (&$options): bool {
            unset($options[$key]);

            return true;
        });
    }
}
