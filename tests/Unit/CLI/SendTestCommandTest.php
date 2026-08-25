<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\SendTestCommand;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers the send-test command logic in isolation: recipient validation, the debug-enabled wp_mail
 * dispatch, and success/failure reporting -- all with a mocked WpMailBridge and a fake reporter, no
 * real WP_CLI.
 *
 * @internal
 *
 * @coversNothing
 */
final class SendTestCommandTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_email')->alias(static fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false);
    }

    public function testReportsSuccessWhenTheSendDoesNotFail(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        $bridge->shouldReceive('setDebug')->once()->with(true)->andReturnSelf();
        $bridge->shouldReceive('isFailed')->once()->andReturnFalse();
        $bridge->shouldNotReceive('getDebugOutput');

        Functions\expect('wp_mail')->once()->with('user@example.com', 'Bit SMTP test email', Mockery::type('string'))->andReturnTrue();

        $reporter = new FakeCliReporter();
        (new SendTestCommand($bridge))->run(['user@example.com'], [], $reporter);

        $this->assertSame(['Test email sent to user@example.com'], $reporter->success);
        $this->assertSame([], $reporter->error);
    }

    public function testUsesCustomSubjectAndMessageFlags(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        $bridge->shouldReceive('setDebug')->andReturnSelf();
        $bridge->shouldReceive('isFailed')->andReturnFalse();

        Functions\expect('wp_mail')->once()->with('user@example.com', 'Hi', 'Body here')->andReturnTrue();

        $reporter = new FakeCliReporter();
        (new SendTestCommand($bridge))->run(
            ['user@example.com'],
            ['subject' => 'Hi', 'message' => 'Body here'],
            $reporter
        );

        $this->assertSame(['Test email sent to user@example.com'], $reporter->success);
    }

    public function testReportsDebugOutputAndErrorWhenTheSendFails(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        $bridge->shouldReceive('setDebug')->andReturnSelf();
        $bridge->shouldReceive('isFailed')->once()->andReturnTrue();
        $bridge->shouldReceive('getDebugOutput')->once()->andReturn(['SMTP connect() failed', 'auth rejected']);

        Functions\expect('wp_mail')->once()->andReturnFalse();

        $reporter = new FakeCliReporter();
        (new SendTestCommand($bridge))->run(['user@example.com'], [], $reporter);

        $this->assertSame(['SMTP connect() failed', 'auth rejected'], $reporter->lines);
        $this->assertSame(['Test email to user@example.com failed to send.'], $reporter->error);
        $this->assertSame([], $reporter->success);
    }

    public function testRejectsAnInvalidRecipientWithoutSending(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        $bridge->shouldNotReceive('setDebug');

        Functions\expect('wp_mail')->never();

        $reporter = new FakeCliReporter();
        (new SendTestCommand($bridge))->run(['not-an-email'], [], $reporter);

        $this->assertCount(1, $reporter->error);
        $this->assertStringContainsString('not-an-email', $reporter->error[0]);
    }

    public function testRejectsAMissingRecipientAsEmpty(): void
    {
        $bridge = Mockery::mock(WpMailBridge::class);
        Functions\expect('wp_mail')->never();

        $reporter = new FakeCliReporter();
        (new SendTestCommand($bridge))->run([], [], $reporter);

        $this->assertCount(1, $reporter->error);
        $this->assertStringContainsString('(empty)', $reporter->error[0]);
    }
}
