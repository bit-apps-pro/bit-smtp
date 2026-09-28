<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class NotificationChannelTesterTest extends BaseUnitTestCase
{
    private const BOT_TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz';

    private const WHSEC = 'whsec_abcdefghijklmnopqrstuvwxyz012345';

    private const SLACK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXX';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
    }

    public function testItSendsUnsavedSettingsSanitizedToTheTypeKeys(): void
    {
        $channel = $this->expectTestSend('discord', ['webhook_url' => 'https://discord.com/api/webhooks/123/token']);

        $sent = $this->tester([], [$channel])->send('discord', [
            'webhook_url' => ' https://discord.com/api/webhooks/123/token ',
            'ignored'     => 'drop me',
        ]);

        $this->assertTrue($sent->ok());
    }

    public function testItResolvesAMaskedSecretFromTheStoredInstanceWithTheSameIdAndType(): void
    {
        $channel = $this->expectTestSend('telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-100999']);
        $stored  = [$this->instance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-100111'])];

        $sent = $this->tester($stored, [$channel])->send(
            'telegram',
            ['bot_token' => MailSettingsSerializer::MASK_SENTINEL, 'chat_id' => '-100999'],
            'ch_tele001'
        );

        $this->assertTrue($sent->ok());
    }

    public function testItResolvesTheMaskOfTheMatchingSameTypeInstanceOnly(): void
    {
        $channel = $this->expectTestSend('webhook', ['url' => 'https://b.example.com/hook', 'signing_secret' => self::WHSEC . 'B']);
        $stored  = [
            $this->instance('ch_hookaaa', 'webhook', ['url' => 'https://a.example.com/hook', 'signing_secret' => self::WHSEC . 'A']),
            $this->instance('ch_hookbbb', 'webhook', ['url' => 'https://b.example.com/hook', 'signing_secret' => self::WHSEC . 'B']),
        ];

        $sent = $this->tester($stored, [$channel])->send(
            'webhook',
            ['url' => 'https://b.example.com/hook', 'signing_secret' => MailSettingsSerializer::MASK_SENTINEL],
            'ch_hookbbb'
        );

        $this->assertTrue($sent->ok());
    }

    public function testAMaskedSecretDoesNotResolveAcrossATypeChange(): void
    {
        $channel = $this->channel('discord');
        $channel->shouldNotReceive('send');
        $stored = [$this->instance('ch_swap001', 'slack', ['webhook_url' => self::SLACK_URL])];

        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage('Add a Discord webhook URL first.');

        $this->tester($stored, [$channel])->send('discord', ['webhook_url' => MailSettingsSerializer::MASK_SENTINEL], 'ch_swap001');
    }

    #[DataProvider('incompleteChannelProvider')]
    public function testItRejectsAChannelMissingARequiredField(string $type, array $settings, string $message): void
    {
        $channel = $this->channel($type);
        $channel->shouldNotReceive('send');

        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage($message);

        $this->tester([], [$channel])->send($type, $settings);
    }

    public static function incompleteChannelProvider(): array
    {
        return [
            'email without recipients'   => ['email', ['recipients' => ['not-an-email']], 'Add at least one valid recipient email first.'],
            'webhook without url'        => ['webhook', ['url' => 'ftp://x', 'signing_secret' => self::WHSEC], 'Add a valid webhook URL first.'],
            'webhook without secret'     => ['webhook', ['url' => 'https://a.example.com/hook'], 'Add a webhook signing secret first.'],
            'slack with a foreign url'   => ['slack', ['webhook_url' => 'https://evil.example.com/services/x'], 'Add a Slack webhook URL first.'],
            'telegram without chat id'   => ['telegram', ['bot_token' => self::BOT_TOKEN], 'Add a Telegram chat ID first.'],
            'masked secret with no id'   => ['slack', ['webhook_url' => MailSettingsSerializer::MASK_SENTINEL], 'Add a Slack webhook URL first.'],
        ];
    }

    #[DataProvider('unknownTypeProvider')]
    public function testItRejectsUnknownTypes(string $type): void
    {
        $channel = $this->channel('slack');
        $channel->shouldNotReceive('send');

        $this->expectException(InvalidAlertChannelException::class);

        $this->tester([], [$channel])->send($type, ['webhook_url' => self::SLACK_URL]);
    }

    public static function unknownTypeProvider(): array
    {
        return [
            'unknown'        => ['unknown'],
            'empty'          => [''],
            'not registered' => ['discord'],
        ];
    }

    public function testAThrowingChannelReportsFailureWithoutLeaking(): void
    {
        $channel = $this->channel('slack');
        $channel->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));

        $result = $this->tester([], [$channel])->send('slack', ['webhook_url' => self::SLACK_URL]);

        $this->assertFalse($result->ok());
        $this->assertSame('Notification test failed.', $result->reason());
    }

    /**
     * A mocked channel expecting exactly one test notification carrying the given settings.
     */
    private function expectTestSend(string $type, array $settings): FailureNotificationChannelInterface
    {
        $channel = $this->channel($type);
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $notification): bool => $notification->isTest()), $settings)
            ->andReturn(ChannelDeliveryResult::delivered());

        return $channel;
    }

    /**
     * One channel instance in the stored shape.
     */
    private function instance(string $id, string $type, array $settings): array
    {
        return ['id' => $id, 'type' => $type, 'name' => '', 'enabled' => false, 'settings' => $settings];
    }

    /**
     * A tester over the given stored instances (alerts master switch off) and registered channels.
     *
     * @param FailureNotificationChannelInterface[] $channels
     */
    private function tester(array $storedChannels, array $channels): NotificationChannelTester
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn(MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => ['enabled' => false, 'channels' => $storedChannels]],
        ]));

        return new NotificationChannelTester($config, new FailureNotificationChannelRegistry($channels));
    }

    /**
     * A mocked channel adapter registered under the given type.
     */
    private function channel(string $type): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->andReturn($type);

        return $channel;
    }
}
