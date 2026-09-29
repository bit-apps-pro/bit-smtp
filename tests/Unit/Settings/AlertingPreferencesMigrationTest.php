<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitSmtpAlertingPreferencesUpgrade;
use Brain\Monkey\Functions;

require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpAlertingPreferencesUpgrade.php';

/**
 * @internal
 *
 * @coversNothing
 */
final class AlertingPreferencesMigrationTest extends BaseUnitTestCase
{
    private const MAIL_OPTIONS = 'bit_smtp_options';

    public function testUpRewritesALegacyBlobAndFoldsTheCooldownIntoTheRepeatInterval(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => [
                'retry_on_classes'        => [],
                'notify_events'           => [HealthNotification::EVENT_UNHEALTHY],
                'notify_cooldown_minutes' => 120,
            ],
            self::MAIL_OPTIONS => ['features' => ['alerts' => ['enabled' => true, 'reminder_minutes' => 60, 'channels' => ['kept']]]],
        ];
        $this->stubOptionsApi($options);

        (new BitSmtpAlertingPreferencesUpgrade())->up();

        $preferences = $options[PluginSettings::OPTION_NAME];
        self::assertSame(FailureCategory::retryableClasses(), $preferences['retry_on_classes']);
        self::assertSame([AlertEvents::SEND_FAILURE, HealthNotification::EVENT_UNHEALTHY], $preferences['notify_events']);
        self::assertArrayNotHasKey('notify_cooldown_minutes', $preferences);
        self::assertSame(
            ['enabled' => true, 'reminder_minutes' => 240, 'channels' => ['kept']],
            $options[self::MAIL_OPTIONS]['features']['alerts']
        );
    }

    public function testUpLeavesTheMailOptionsAloneWhenTheMergeChangesNothing(): void
    {
        $mail    = ['features' => ['alerts' => ['enabled' => true, 'reminder_minutes' => 60, 'channels' => []]]];
        $options = [
            PluginSettings::OPTION_NAME => ['notify_events' => [], 'notify_cooldown_minutes' => 0],
            self::MAIL_OPTIONS          => $mail,
        ];
        $this->stubOptionsApi($options);

        (new BitSmtpAlertingPreferencesUpgrade())->up();

        self::assertSame($mail, $options[self::MAIL_OPTIONS]);
        self::assertSame([AlertEvents::SEND_FAILURE], $options[PluginSettings::OPTION_NAME]['notify_events']);
    }

    public function testUpIsANoOpForAnAlreadyUpgradedBlob(): void
    {
        $current = ['retry_on_classes' => [FailureCategory::TRANSIENT], 'notify_events' => []];
        $options = [PluginSettings::OPTION_NAME => $current];
        $this->stubOptionsApi($options);
        Functions\expect('update_option')->never();

        (new BitSmtpAlertingPreferencesUpgrade())->up();

        self::assertSame($current, $options[PluginSettings::OPTION_NAME]);
    }

    public function testUpDoesNothingBeforePreferencesAreSeeded(): void
    {
        $options = [];
        $this->stubOptionsApi($options);
        Functions\expect('update_option')->never();

        (new BitSmtpAlertingPreferencesUpgrade())->up();

        self::assertSame([], $options);
    }

    /**
     * Back get_option/update_option with an in-memory array.
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
    }
}
