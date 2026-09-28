<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\NotificationController;
use BitApps\SMTP\HTTP\Requests\NotificationTestRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class NotificationControllerTest extends BaseUnitTestCase
{
    private const BOT_TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();
        Response::reset();
        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    public function testItSendsTheUnsavedChannelWithTheStoredSecretBehindTheMask(): void
    {
        $channel = $this->channel();
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::any(), ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-100222'])
            ->andReturn(ChannelDeliveryResult::delivered());

        (new NotificationController($this->tester($channel)))->test($this->request([
            'id'       => 'ch_tele001',
            'type'     => 'telegram',
            'settings' => ['bot_token' => MailSettingsSerializer::MASK_SENTINEL, 'chat_id' => '-100222'],
        ]));

        $this->assertSame(Response::SUCCESS, Response::getStatus());
    }

    public function testItReturnsTheChannelsFailureReasonAsTheMessage(): void
    {
        $channel = $this->channel();
        $channel->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::failed('Telegram rejected the request: Unauthorized.'));

        (new NotificationController($this->tester($channel)))->test($this->request([
            'id'       => 'ch_tele001',
            'type'     => 'telegram',
            'settings' => ['bot_token' => MailSettingsSerializer::MASK_SENTINEL, 'chat_id' => '-100222'],
        ]));

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame([], Response::getData());
        $this->assertSame('Telegram rejected the request: Unauthorized.', Response::getMessage());
    }

    public function testItReportsTheMissingRequiredFieldWithoutSending(): void
    {
        $channel = $this->channel();
        $channel->shouldNotReceive('send');

        (new NotificationController($this->tester($channel)))->test($this->request([
            'type'     => 'telegram',
            'settings' => ['bot_token' => self::BOT_TOKEN],
        ]));

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame('Add a Telegram chat ID first.', Response::getMessage());
    }

    public function testItRejectsAnUnknownOrMalformedChannel(): void
    {
        $channel = $this->channel();
        $channel->shouldNotReceive('send');

        foreach ([['type' => 'sms'], 'telegram'] as $payload) {
            Response::reset();
            (new NotificationController($this->tester($channel)))->test($this->request($payload));

            $this->assertSame(Response::ERROR, Response::getStatus());
            $this->assertSame('Unknown notification channel type.', Response::getMessage());
        }
    }

    public function testItRejectsRequestsWithoutAdminCapability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $channel = $this->channel();
        $channel->shouldNotReceive('send');
        $request = Mockery::mock(NotificationTestRequest::class);
        $request->shouldNotReceive('validated');

        (new NotificationController($this->tester($channel)))->test($request);

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    /**
     * A request whose validated payload carries the given `channel` value.
     *
     * @param mixed $channel
     */
    private function request($channel): NotificationTestRequest
    {
        $request = Mockery::mock(NotificationTestRequest::class);
        $request->shouldReceive('validated')->andReturn(['channel' => $channel]);

        return $request;
    }

    /**
     * A mocked Telegram channel adapter.
     */
    private function channel(): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->andReturn('telegram');

        return $channel;
    }

    /**
     * A tester over one stored Telegram instance and the given adapter.
     */
    private function tester(FailureNotificationChannelInterface $channel): NotificationChannelTester
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn(MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => ['enabled' => true, 'channels' => [[
                'id'       => 'ch_tele001',
                'type'     => 'telegram',
                'enabled'  => true,
                'settings' => ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-100111'],
            ]]]],
        ]));

        return new NotificationChannelTester($config, new FailureNotificationChannelRegistry([$channel]));
    }
}
