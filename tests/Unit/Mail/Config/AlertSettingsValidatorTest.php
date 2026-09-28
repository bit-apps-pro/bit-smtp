<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\AlertSettingsRevision;
use BitApps\SMTP\Mail\Config\AlertSettingsValidator;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Mail\Exceptions\AlertSettingsConflictException;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use UnexpectedValueException;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlertSettingsValidatorTest extends BaseUnitTestCase
{
    private const SLACK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('test-auth-salt');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testAPayloadWithoutAlertsIsNotChecked(): void
    {
        $this->expectNotToPerformAssertions();

        AlertSettingsValidator::assertSaveable(['features' => []], ['channels' => []], $this->current([]));
    }

    public function testTheCurrentRevisionPasses(): void
    {
        $stored = $this->alerts([$this->slack('ch_slackaa', 'Ops', true, self::SLACK_URL)]);

        $this->assertValid(['revision' => AlertSettingsRevision::ofStored($this->current($stored))] + $stored, $stored);
    }

    public function testAStaleRevisionIsAConflict(): void
    {
        $stored = $this->alerts([$this->slack('ch_slackaa', 'Ops', true, self::SLACK_URL)]);
        $stale  = AlertSettingsRevision::ofStored($this->current($this->alerts([])));

        $this->expectException(AlertSettingsConflictException::class);
        $this->expectExceptionMessage('Notification settings were changed in another tab. Reload to see the latest.');

        $this->assertValid(['revision' => $stale] + $stored, $stored);
    }

    public function testANonStringRevisionIsAConflict(): void
    {
        $this->expectException(AlertSettingsConflictException::class);

        $this->assertValid(['revision' => ['x']] + $this->alerts([]), $this->alerts([]));
    }

    public function testAnAbsentOrEmptyRevisionSkipsTheCheck(): void
    {
        $stored = $this->alerts([$this->slack('ch_slackaa', 'Ops', true, self::SLACK_URL)]);

        $this->assertValid($this->alerts([]), $stored);
        $this->assertValid(['revision' => ''] + $this->alerts([]), $stored);
    }

    public function testTheRevisionIsAKeyedHashThatNeverEmbedsSecrets(): void
    {
        $settings = $this->current($this->alerts([$this->slack('ch_slackaa', 'Ops', true, self::SLACK_URL)]));
        $revision = AlertSettingsRevision::ofStored($settings);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $revision);
        $this->assertNotSame(substr(hash('sha256', (string) json_encode($settings->getFeatures()['alerts'])), 0, 32), $revision);
        Functions\when('wp_salt')->justReturn('another-salt');
        $this->assertNotSame($revision, AlertSettingsRevision::ofStored($settings));
    }

    public function testStoredSettingsWithoutFeaturesAcceptTheRevisionTheApiReportedForThem(): void
    {
        $bare     = MailSettings::fromArray(['schema_version' => 2, 'connections' => []]);
        $revision = AlertSettingsRevision::ofStored($bare);

        $this->assertSame(AlertSettingsRevision::ofStored(MailSettings::fromArray(['features' => ['alerts' => []]])), $revision);
        AlertSettingsValidator::assertSaveable(['features' => ['alerts' => ['revision' => $revision]]], ['channels' => []], $bare);
    }

    public function testARevisionThatCannotBeEncodedFailsLoudly(): void
    {
        Functions\when('wp_json_encode')->justReturn(false);

        $this->expectException(UnexpectedValueException::class);

        AlertSettingsRevision::ofStored($this->current($this->alerts([])));
    }

    public function testDuplicateChannelIdsAreRejected(): void
    {
        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage('Two notification channels share the same ID. Reload the page and try again.');

        $this->assertValid($this->alerts([
            $this->slack('ch_slackaa', 'Ops', false, self::SLACK_URL),
            $this->slack('ch_slackaa', 'Dev', false, self::SLACK_URL),
        ]), $this->alerts([]));
    }

    public function testAnEnabledIncompleteChannelIsRejectedByName(): void
    {
        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage('Slack channel “Ops” needs a webhook URL.');

        $this->assertValid($this->alerts([$this->slack('ch_slackaa', 'Ops', true, '')]), $this->alerts([]));
    }

    public function testAnEnabledChannelWithAnInvalidValueIsRejected(): void
    {
        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage('Telegram channel “Pager” needs a chat ID.');

        $this->assertValid($this->alerts([[
            'id'       => 'ch_tele001',
            'type'     => 'telegram',
            'name'     => 'Pager',
            'enabled'  => true,
            'settings' => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'chat_id' => 'not-a-chat'],
        ]]), $this->alerts([]));
    }

    public function testADisabledIncompleteChannelIsAllowedEvenWithTheMasterSwitchOn(): void
    {
        $this->assertValid(['enabled' => true] + $this->alerts([$this->slack('ch_slackaa', 'Ops', false, '')]), $this->alerts([]));
    }

    public function testAnEnabledIncompleteChannelIsRejectedEvenWithTheMasterSwitchOff(): void
    {
        $this->expectException(InvalidAlertChannelException::class);

        $this->assertValid(['enabled' => false] + $this->alerts([$this->slack('ch_slackaa', 'Ops', true, '')]), $this->alerts([]));
    }

    public function testAnUnchangedStoredChannelIsNotReValidated(): void
    {
        $stored = $this->alerts([$this->slack('ch_slackaa', 'Ops', true, '')]);

        $this->assertValid($stored, $stored);
    }

    /**
     * Run the validator on an incoming alerts blob (sanitized the way a save would) against stored alerts.
     *
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $stored
     */
    private function assertValid(array $incoming, array $stored): void
    {
        $sanitized = MailSettingsSanitizer::sanitize(['features' => ['alerts' => $incoming]], static fn (): array => []);

        AlertSettingsValidator::assertSaveable(['features' => ['alerts' => $incoming]], $sanitized['features']['alerts'], $this->current($stored));
        $this->addToAssertionCount(1);
    }

    /**
     * Stored settings holding the given alerts, sanitized as a save would store them.
     *
     * @param array<string,mixed> $alerts
     */
    private function current(array $alerts): MailSettings
    {
        return MailSettings::fromArray(MailSettingsSanitizer::sanitize(['features' => ['alerts' => $alerts]], static fn (): array => []));
    }

    /**
     * An alerts blob with the master switch on and the given channels.
     *
     * @param list<array<string,mixed>> $channels
     *
     * @return array<string,mixed>
     */
    private function alerts(array $channels): array
    {
        return ['enabled' => true, 'reminder_minutes' => 60, 'channels' => $channels];
    }

    /**
     * A Slack channel instance.
     *
     * @return array<string,mixed>
     */
    private function slack(string $id, string $name, bool $enabled, string $url): array
    {
        return ['id' => $id, 'type' => 'slack', 'name' => $name, 'enabled' => $enabled, 'settings' => ['webhook_url' => $url]];
    }
}
