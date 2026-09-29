<?php

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class PluginSettingsTest extends BaseUnitTestCase
{
    public function testSchemaDefaultsCoverKeySettingsAcrossAllGroups(): void
    {
        $defaults = PluginSettings::schema()->defaults();

        self::assertSame(true, $defaults['logging_enabled']);
        self::assertSame(30, $defaults['log_retention_days']);
        self::assertSame(30, $defaults['send_timeout_seconds']);
        self::assertSame(false, $defaults['retry_enabled']);
        self::assertSame(false, $defaults['health_check_enabled']);
        self::assertSame(true, $defaults['uninstall_purge']);
        self::assertSame(false, $defaults['tracking_enabled']);
    }

    public function testSchemaGroupsFieldsIntoOneGroupPerSettingsTab(): void
    {
        self::assertSame(['general', 'reliability', 'health', 'notifications', 'privacy'], PluginSettings::schema()->groups());
    }

    public function testRetryAndAlertListsDefaultToEveryAllowedMember(): void
    {
        $defaults = PluginSettings::schema()->defaults();

        self::assertSame(FailureCategory::retryableClasses(), $defaults['retry_on_classes']);
        self::assertSame(AlertEvents::keys(), $defaults['notify_events']);
        self::assertArrayNotHasKey('notify_cooldown_minutes', $defaults);
    }

    public function testSendTimeoutAndRetryAttemptsClampToTheirSafeRanges(): void
    {
        $timeout  = PluginSettings::schema()->field('send_timeout_seconds');
        $attempts = PluginSettings::schema()->field('retry_max_attempts');

        self::assertSame(5, $timeout->cast(1));
        self::assertSame(120, $timeout->cast(300));
        self::assertSame(1, $attempts->cast(0));
        self::assertSame(10, $attempts->cast(255));
    }

    public function testRetryClassesKeepOnlyRetryableMembersInCanonicalOrder(): void
    {
        $field = PluginSettings::schema()->field('retry_on_classes');

        self::assertSame(
            [FailureCategory::TRANSIENT, FailureCategory::RATE_LIMITED],
            $field->cast([FailureCategory::AUTH, FailureCategory::RATE_LIMITED, FailureCategory::TRANSIENT, FailureCategory::TRANSIENT])
        );
    }

    public function testLogStoreBodyEnumDefaultsToFull(): void
    {
        $field = PluginSettings::schema()->field('log_store_body');

        self::assertSame('full', $field->default());
    }

    public function testLogStoreBodyEnumRejectsAnInvalidValueAndFallsBackToTheDefault(): void
    {
        $field = PluginSettings::schema()->field('log_store_body');

        self::assertSame('full', $field->cast('not-a-real-mode'));
        self::assertSame('redacted', $field->cast('redacted'));
    }

    public function testLogRetentionDaysSanitizerClampsToTheAllowedRange(): void
    {
        $field = PluginSettings::schema()->field('log_retention_days');

        self::assertSame(1, $field->cast(0));
        self::assertSame(200, $field->cast(500));
        self::assertSame(45, $field->cast(45));
    }

    public function testMakeReturnsARepositoryOverTheDedicatedPreferencesOption(): void
    {
        Functions\when('get_option')->justReturn([]);

        $repository = PluginSettings::make();

        self::assertInstanceOf(SettingsRepository::class, $repository);
        self::assertSame(true, $repository->get('logging_enabled'));
        self::assertSame(30, $repository->get('log_retention_days'));
    }

    public function testMakePersistsUnderTheBitSmtpPreferencesOptionKeyNotTheLegacySettingsKey(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\expect('update_option')
            ->once()
            ->with('bit_smtp_preferences', Mockery::type('array'), 'yes')
            ->andReturn(true);

        $repository = PluginSettings::make();
        $repository->set('logging_enabled', false);

        self::assertTrue($repository->save());
    }
}
