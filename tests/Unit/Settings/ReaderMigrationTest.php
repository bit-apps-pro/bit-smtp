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
 * Proves Task 14b: logging/retention readers source seeded blob, fallback to legacy options, write paths sync with blob.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReaderMigrationTest extends BaseUnitTestCase
{
    public function testLogServiceIsEnabledReadsFromTheSeededPreferencesBlob(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['logging_enabled' => false, 'log_retention_days' => 7],
            'bit_smtp_logging_enabled'  => 1,
        ];
        $this->stubOptionsApi($options);

        self::assertFalse((new LogService())->isEnabled());
    }

    public function testLogServiceIsEnabledFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $options = ['bit_smtp_logging_enabled' => 0];
        $this->stubOptionsApi($options);

        self::assertFalse((new LogService())->isEnabled());
    }

    public function testRetentionReaderReadsFromTheSeededPreferencesBlob(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['log_retention_days' => 7],
            'bit_smtp_log_retention'    => 45,
        ];
        $this->stubOptionsApi($options);

        self::assertSame(7, (int) PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', 30));
    }

    public function testRetentionReaderFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $options = ['bit_smtp_log_retention' => 45];
        $this->stubOptionsApi($options);

        self::assertSame(45, (int) PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', 30));
    }

    public function testAnalyticsQueryFactoryRetentionReadsFromTheSeededPreferencesBlob(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['log_retention_days' => 7],
            'bit_smtp_log_retention'    => 45,
        ];
        $this->stubOptionsApi($options);

        $query = $this->factoryWithConfiguredRetention()->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('2026-03-08T00:00:00+00:00', $query->start()->format(DATE_ATOM));
    }

    public function testAnalyticsQueryFactoryRetentionFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $options = ['bit_smtp_log_retention' => 7];
        $this->stubOptionsApi($options);

        $query = $this->factoryWithConfiguredRetention()->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('2026-03-08T00:00:00+00:00', $query->start()->format(DATE_ATOM));
    }

    public function testMailAnalyticsServiceLoggingEnabledReadsFromTheSeededPreferencesBlob(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['logging_enabled' => false],
            'bit_smtp_logging_enabled'  => 1,
        ];
        $this->stubOptionsApi($options);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');

        $result = (new MailAnalyticsService($repo))->overview($this->analyticsQuery());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
    }

    public function testMailAnalyticsServiceLoggingEnabledFallsBackToTheLegacyOptionWhenNoPreferencesBlobExists(): void
    {
        $options = ['bit_smtp_logging_enabled' => 0];
        $this->stubOptionsApi($options);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');

        $result = (new MailAnalyticsService($repo))->overview($this->analyticsQuery());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
    }

    public function testSetEnabledWritesThroughToThePreferencesBlobTheReaderConsults(): void
    {
        $options = [
            PluginSettings::OPTION_NAME        => ['logging_enabled' => true],
            'bit_smtp_logging_enabled'         => 1,
            'bit_smtp_logging_continuity_from' => '2026-03-01 00:00:00',
        ];
        $this->stubOptionsApi($options);
        $service = new LogService();

        self::assertTrue($service->setEnabled(false));
        self::assertFalse($service->isEnabled());
        self::assertFalse($options[PluginSettings::OPTION_NAME]['logging_enabled']);
        self::assertSame(0, $options['bit_smtp_logging_enabled']);
    }

    public function testUpdateRetentionWritesThroughToThePreferencesBlobTheReaderConsults(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['log_retention_days' => 30],
            'bit_smtp_log_retention'    => 30,
        ];
        $this->stubOptionsApi($options);

        self::assertTrue((new LogService())->updateRetention(7));
        self::assertSame(7, (int) PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', 30));
        self::assertSame(7, $options[PluginSettings::OPTION_NAME]['log_retention_days']);
        self::assertSame(7, $options['bit_smtp_log_retention']);
    }

    /**
     * Factory without explicit retention; resolves configured value same way production code does.
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
     * Stubs get_option/update_option/delete_option against in-memory map for write-through assertions.
     *
     * @param array<string,mixed> $options
     */
    private function stubOptionsApi(array &$options): void
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
