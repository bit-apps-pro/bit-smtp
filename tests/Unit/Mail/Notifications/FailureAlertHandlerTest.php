<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\FailureAlertHandler;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Unit\Mail\Notifications\Support\FailureAlertFixtures;
use Mockery;
use RuntimeException;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureAlertHandlerTest extends BaseUnitTestCase
{
    use FailureAlertFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFailureAlertFixtures();
    }

    public function testOpenedAlertGoesToEveryEnabledChannelAndIsMarkedDelivered(): void
    {
        $email   = $this->channel('email');
        $webhook = $this->channel('webhook');
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn ($n): bool => $n instanceof FailureNotification && $n->eventType() === FailureNotification::EVENT_OPENED), ['recipients' => ['ops@example.com']])
            ->andReturn(ChannelDeliveryResult::delivered());
        $webhook->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FailureNotification::class), ['url' => 'https://hooks.example.com/failure'])
            ->andReturn(ChannelDeliveryResult::failed('Rejected.'));
        $alerts                = $this->emailAlerts();
        $alerts['channels'][]  = ['id' => 'ch_hook001', 'type' => 'webhook', 'enabled' => true, 'settings' => ['url' => 'https://hooks.example.com/failure']];

        $this->notifier([$email, $webhook], $alerts)->notifyFailure($this->error());
        $this->now += 3;
        $this->runScheduled($this->handler([$email, $webhook], $alerts));

        $stored = $this->storedIncident()?->toArray();
        $this->assertTrue($stored['opened_notified']);
        $this->assertNull($stored['pending_since']);
        $this->assertSame($this->now, $stored['last_notified_at']);
    }

    public function testOneThrowingChannelDoesNotStopTheNextOne(): void
    {
        $slack    = $this->channel('slack');
        $telegram = $this->channel('telegram');
        $slack->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));
        $telegram->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $alerts = [
            'enabled'  => true,
            'channels' => [
                ['id' => 'ch_slack01', 'type' => 'slack', 'enabled' => true, 'settings' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret']],
                ['id' => 'ch_tele001', 'type' => 'telegram', 'enabled' => true, 'settings' => ['bot_token' => '123456:secret', 'chat_id' => '-1001234567890']],
            ],
        ];

        $this->notifier([$slack, $telegram], $alerts)->notifyFailure($this->error());
        $this->runScheduled($this->handler([$slack, $telegram], $alerts));

        $this->assertTrue($this->storedIncident()?->toArray()['opened_notified']);
    }

    public function testUndeliveredOpenedAlertLeavesTheIncidentOwingIt(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::failed('Rejected.'));

        $this->notifier([$email], $this->emailAlerts())->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));

        $stored = $this->storedIncident()?->toArray();
        $this->assertFalse($stored['opened_notified']);
        $this->assertNull($stored['pending_since']);
        $this->assertNull($stored['last_notified_at']);
    }

    public function testAlertIsRenderedFromTheFreshestIncidentState(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $n): bool => $n->toArray()['incident']['failed_count'] === 3), Mockery::any())
            ->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $notifier->notifyFailure($this->error());
        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));
    }

    public function testAnOpenedAlertForAnAlreadyResolvedIncidentIsDroppedWhileTheResolutionGoesOut(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $notifier->notifySuccess();
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));

        $this->assertNull($this->gate->state);
    }

    public function testResolvedAlertIsDeliveredFromItsSnapshotAfterTheIncidentClosed(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $n): bool => $n->eventType() === FailureNotification::EVENT_RESOLVED
                && $n->toArray()['incident']['failed_count']                               === 2
                && $n->toArray()['incident']['duration_seconds']                           === 120), Mockery::any())
            ->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $handler  = $this->handler([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $notifier->notifyFailure($this->error());
        $this->now += 120;
        $notifier->notifySuccess();
        $this->runScheduled($handler);
    }

    public function testAnUndeliveredResolvedAlertIsKeptAndRequeuedWithBackoff(): void
    {
        [$notifier, $handler] = $this->resolvedFlow(resolvedAccepted: false);

        $notifier->notifySuccess();
        $this->runScheduled($handler);

        $this->assertArrayHasKey('incident-1', $this->keptResolved());
        $this->assertCount(1, $this->scheduled);
        $this->assertSame($this->now + FailureAlertHandler::RESOLVED_RETRY_DELAYS[0], $this->scheduled[0]['timestamp']);
        $this->assertSame([FailureNotification::EVENT_RESOLVED, 'incident-1', 1, 1], $this->scheduled[0]['args']);
    }

    public function testADeliveredResolvedAlertIsForgottenWithoutRetry(): void
    {
        [$notifier, $handler] = $this->resolvedFlow(resolvedAccepted: true);

        $notifier->notifySuccess();
        $this->runScheduled($handler);

        $this->assertSame([], $this->keptResolved());
        $this->assertSame([], $this->scheduled);
    }

    public function testResolvedRetriesGiveUpAfterTheCapAndForgetTheRecord(): void
    {
        [$notifier, $handler] = $this->resolvedFlow(resolvedAccepted: false);
        $notifier->notifySuccess();

        $attempts = [];
        while ($this->scheduled !== []) {
            $attempts[] = $this->scheduled[0]['args'][3] ?? 0;
            $this->runScheduled($handler);
        }

        $this->assertSame([0, 1, 2, 3], $attempts);
        $this->assertSame([], $this->keptResolved());
    }

    public function testAnOutOfRangeRetryAttemptIsIgnored(): void
    {
        [$notifier, $handler] = $this->resolvedFlow(resolvedAccepted: true);
        $notifier->notifySuccess();

        $handler->handle(FailureNotification::EVENT_RESOLVED, 'incident-1', 1, \count(FailureAlertHandler::RESOLVED_RETRY_DELAYS) + 1);
        $handler->handle(FailureNotification::EVENT_RESOLVED, 'incident-1', 1, '1');

        $this->assertArrayHasKey('incident-1', $this->keptResolved());
    }

    public function testAnUndeliveredReminderIsRequeuedByTheNextFailure(): void
    {
        $outcomes = [true, false, true];
        $email    = $this->channel('email');
        $email->shouldReceive('send')->andReturnUsing(static function () use (&$outcomes): ChannelDeliveryResult {
            return array_shift($outcomes) ? ChannelDeliveryResult::delivered() : ChannelDeliveryResult::failed('Timed out.');
        });
        $notifier = $this->notifier([$email], $this->emailAlerts(60));
        $handler  = $this->handler([$email], $this->emailAlerts(60));

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $this->now += 3600;
        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $this->now += 60;
        $notifier->notifyFailure($this->error());

        $this->assertSame(FailureNotification::EVENT_REMINDER, $this->scheduled[0]['args'][0] ?? null);
    }

    public function testMalformedCronArgsAreIgnored(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->never();
        $handler = $this->handler([$email], $this->emailAlerts());

        $this->notifier([$email], $this->emailAlerts())->notifyFailure($this->error());
        $handler->handle('email_send_failed', 'incident-1', 1);
        $handler->handle(FailureNotification::EVENT_OPENED, ['id' => 'incident-1'], 1);
        $handler->handle(FailureNotification::EVENT_OPENED, 'incident-1', '1');
        $handler->handle(FailureNotification::EVENT_OPENED, '', 1);
        $handler->handle(null, null, null);

        $this->assertFalse($this->storedIncident()?->toArray()['opened_notified']);
    }

    public function testEventsWhoseStateIsGoneAreDroppedSilently(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $handler  = $this->handler([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $notifier->notifyFailure($this->error());
        $notifier->notifySuccess();
        $this->gate->state         = null;
        $this->resolvedGate->state = null;

        $this->runScheduled($handler);
        $handler->handle(FailureNotification::EVENT_REMINDER, 'incident-1', 9);

        $this->assertSame([], $this->keptResolved());
    }

    public function testAResolvedAlertForAStaleSequenceIsDroppedButKept(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $handler  = $this->handler([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $notifier->notifySuccess();

        $handler->handle(FailureNotification::EVENT_RESOLVED, 'incident-1', 7);

        $this->assertArrayHasKey('incident-1', $this->keptResolved());
    }

    public function testResolvedAlertStillRendersAfterANewIncidentHasOpened(): void
    {
        $delivered = [];
        $email     = $this->channel('email');
        $email->shouldReceive('send')->andReturnUsing(static function (FailureNotification $n) use (&$delivered): ChannelDeliveryResult {
            $delivered[] = [$n->eventType(), $n->toArray()['incident']['id'], $n->toArray()['incident']['failed_count']];

            return ChannelDeliveryResult::delivered();
        });
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $handler  = $this->handler([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);
        $notifier->notifyFailure($this->error());
        $notifier->notifySuccess();
        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);

        $this->assertSame([
            [FailureNotification::EVENT_OPENED, 'incident-1', 1],
            [FailureNotification::EVENT_RESOLVED, 'incident-1', 2],
            [FailureNotification::EVENT_OPENED, 'incident-3', 1],
        ], $delivered);
        $this->assertSame([], $this->keptResolved());
        $this->assertSame('incident-3', $this->storedIncident()?->id());
    }

    /**
     * Notifier/handler pair with delivered opened alert; resolved alert accepted or not; incident ready for notifySuccess().
     *
     * @return array{0:FailureNotifier,1:FailureAlertHandler}
     */
    private function resolvedFlow(bool $resolvedAccepted): array
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturnUsing(
            static fn (FailureNotification $n): ChannelDeliveryResult => $n->eventType() !== FailureNotification::EVENT_RESOLVED || $resolvedAccepted
                ? ChannelDeliveryResult::delivered()
                : ChannelDeliveryResult::failed('cURL error 28: Operation timed out')
        );
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $handler  = $this->handler([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);

        return [$notifier, $handler];
    }

    /**
     * A wp_mail failure.
     */
    private function error(): WP_Error
    {
        return new WP_Error('wp_mail_failed', 'Failed', ['to' => ['to@example.com']]);
    }
}
