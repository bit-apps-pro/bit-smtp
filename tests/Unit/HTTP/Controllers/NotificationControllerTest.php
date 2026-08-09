<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\NotificationController;
use BitApps\SMTP\HTTP\Requests\NotificationTestRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
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
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    public function testItReturnsAGenericFailureWithoutCredentialsOrProviderBodies(): void
    {
        $providerBody = 'Telegram said bot-token-123 is invalid';
        $tester       = $this->tester(false);
        $request      = Mockery::mock(NotificationTestRequest::class);
        $request->shouldReceive('validated')->once()->andReturn(['channel' => 'telegram']);

        (new NotificationController($tester))->test($request);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame([], Response::getData());
        $this->assertStringNotContainsString('bot-token-123', (string) Response::getMessage());
        $this->assertStringNotContainsString($providerBody, (string) Response::getMessage());
    }

    public function testItRejectsRequestsWithoutAdminCapability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $tester  = $this->tester(true);
        $request = Mockery::mock(NotificationTestRequest::class);

        (new NotificationController($tester))->test($request);

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    private function tester(bool $shouldSend): NotificationChannelTester
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->once()->andReturn('telegram');
        if ($shouldSend) {
            $channel->shouldNotReceive('send');
        } else {
            $channel->shouldReceive('send')->once()->andReturn(false);
        }

        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn(MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => ['telegram' => [
                'enabled'   => true,
                'bot_token' => 'bot-token-123',
                'chat_id'   => '-1001234567890',
            ]]],
        ]));

        return new NotificationChannelTester($config, new FailureNotificationChannelRegistry([$channel]));
    }
}
