<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\SMTPController;
use BitApps\SMTP\HTTP\Requests\MailTestRequest;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

/**
 * @internal
 *
 * @coversNothing
 */
final class SMTPControllerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::reset();
        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('remove_filter')->justReturn(true);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Plugin::class, '_instance'))->setValue(null, null);
        Response::reset();
        parent::tearDown();
    }

    public function testAnErrorThrownWhileSendingTheTestEmailBecomesACleanErrorResponse(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        $bridge->shouldReceive('setDebug');
        $this->installPluginWithBridge($bridge);
        Functions\when('wp_mail')->alias(static function (): bool {
            throw new TypeError('provider blew up');
        });

        (new SMTPController())->sendTestEmail($this->testRequest());

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame('Mail send testing failed', Response::getMessage());
    }

    /**
     * A validated test-mail request with no chosen connection.
     */
    private function testRequest(): MailTestRequest
    {
        $request = Mockery::mock(MailTestRequest::class);
        $request->shouldReceive('validated')->andReturn(['to' => 'admin@example.test', 'subject' => 'Hi', 'message' => 'Body']);

        return $request;
    }

    /**
     * Register a Plugin singleton whose container resolves the given mail bridge.
     */
    private function installPluginWithBridge(WpMailBridge $bridge): void
    {
        $app = new Application();
        $app->instance(WpMailBridge::class, $bridge);
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Plugin::class, 'app'))->setValue($plugin, $app);
        (new ReflectionProperty(Plugin::class, '_instance'))->setValue(null, $plugin);
    }
}
