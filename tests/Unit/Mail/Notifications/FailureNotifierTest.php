<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\LosingFailureNotificationGate;
use BitApps\SMTP\Tests\Unit\Mail\Notifications\Support\FailureAlertFixtures;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotifierTest extends BaseUnitTestCase
{
    use FailureAlertFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFailureAlertFixtures();
    }

    public function testFirstFailureOpensTheIncidentAndOnlySchedulesTheOpenedAlert(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->never();

        $this->notifier([$email], $this->emailAlerts())->notifyFailure($this->error('Connection refused'));

        $this->assertCount(1, $this->scheduled);
        $this->assertSame($this->now, $this->scheduled[0]['timestamp']);
        $this->assertSame(Config::FAILURE_ALERT_HOOK, $this->scheduled[0]['hook']);
        $this->assertSame([FailureNotification::EVENT_OPENED, 'incident-1', 1], $this->scheduled[0]['args']);
        $this->assertSame(1, $this->storedIncident()?->failedCount());
        $this->assertSame('Connection refused', $this->storedIncident()?->error()['message']);
    }

    public function testNoEnabledChannelIsANoOpThatOpensNoIncident(): void
    {
        $email  = $this->channel('email');
        $alerts = ['enabled' => false] + $this->emailAlerts();

        $this->notifier([$email], $alerts)->notifyFailure($this->error());

        $this->assertSame([], $this->scheduled);
        $this->assertNull($this->gate->state);
    }

    public function testNothingIsTrackedOrQueuedWhenSendFailureAlertsAreUnsubscribed(): void
    {
        Functions\when('get_option')->justReturn(['notify_events' => [HealthNotification::EVENT_UNHEALTHY]]);
        $notifier = $this->notifier([$this->channel('email')], $this->emailAlerts());

        $notifier->notifyFailure($this->error('Connection refused'));
        $notifier->notifySuccess();

        $this->assertSame([], $this->scheduled);
        $this->assertNull($this->storedIncident());
    }

    public function testFailuresWhileTheOpenedAlertIsInFlightOnlyCount(): void
    {
        $notifier = $this->notifier([$this->channel('email')], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->now += 30;
        $notifier->notifyFailure($this->error());
        $notifier->notifyFailure($this->error('Last straw'));

        $this->assertCount(1, $this->scheduled);
        $this->assertSame(3, $this->storedIncident()?->failedCount());
        $this->assertSame('Last straw', $this->storedIncident()?->error()['message']);
    }

    public function testReminderIsSuppressedWithinTheWindowAndQueuedAfterItWithTheRunningCount(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts(60));
        $handler  = $this->handler([$email], $this->emailAlerts(60));

        $notifier->notifyFailure($this->error());
        $this->runScheduled($handler);

        $this->now += 59 * 60;
        $notifier->notifyFailure($this->error());
        $this->assertSame([], $this->scheduled);

        $this->now += 60;
        $notifier->notifyFailure($this->error('Still down'));

        $this->assertCount(1, $this->scheduled);
        $this->assertSame([FailureNotification::EVENT_REMINDER, 'incident-1', 2], $this->scheduled[0]['args']);
        $this->assertSame(3, $this->scheduledIncident(0)?->failedCount());
        $this->assertSame('Still down', $this->scheduledIncident(0)?->error()['message']);
    }

    public function testZeroReminderMinutesSendsOnlyOpenedAndResolved(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts(0));

        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts(0)));
        $this->now += 86400;
        $notifier->notifyFailure($this->error());

        $this->assertSame([], $this->scheduled);
    }

    public function testUndeliveredOpenedAlertIsRetriedByTheNextFailure(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::failed('Rejected.'));
        $notifier = $this->notifier([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));
        $this->now += 5;
        $notifier->notifyFailure($this->error());

        $this->assertCount(1, $this->scheduled);
        $this->assertSame([FailureNotification::EVENT_OPENED, 'incident-1', 2], $this->scheduled[0]['args']);
        $this->assertSame(2, $this->scheduledIncident(0)?->failedCount());
    }

    public function testAnAlertStuckInFlightIsRequeuedAfterThePendingTimeout(): void
    {
        $notifier = $this->notifier([$this->channel('email')], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->now += FailureIncident::PENDING_TIMEOUT_SECONDS;
        $notifier->notifyFailure($this->error());

        $this->assertCount(2, $this->scheduled);
        $this->assertSame(FailureNotification::EVENT_OPENED, $this->scheduled[1]['args'][0]);
        $this->assertNotSame($this->scheduled[0]['args'], $this->scheduled[1]['args']);
    }

    public function testSuccessResolvesWithTheTotalFailedCountAndDuration(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts());
        $started  = $this->now;

        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));
        $this->now += 60;
        $notifier->notifyFailure($this->error());
        $notifier->notifyFailure($this->error('Final error'));
        $this->now = $started + 4380;
        $notifier->notifySuccess();

        $this->assertNull($this->gate->state);
        $this->assertCount(1, $this->scheduled);
        $this->assertSame([FailureNotification::EVENT_RESOLVED, 'incident-1', 1], $this->scheduled[0]['args']);
        $resolved = $this->scheduledIncident(0);
        $this->assertSame(3, $resolved?->failedCount());
        $this->assertSame($started, $resolved?->startedAt());
        $this->assertSame($started + 4380, $resolved?->resolvedAt());
        $this->assertSame('Final error', $resolved?->error()['message']);
    }

    public function testNoResolvedAlertIsQueuedWhenTheIncidentCannotBeKept(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturn(ChannelDeliveryResult::delivered());
        $this->resolvedGate = new LosingFailureNotificationGate();
        $notifier           = $this->notifier([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));
        $notifier->notifySuccess();

        $this->assertNull($this->gate->state);
        $this->assertSame([], $this->scheduled);
    }

    public function testSuccessWithoutAnOpenIncidentSchedulesNothing(): void
    {
        $this->notifier([$this->channel('email')], $this->emailAlerts())->notifySuccess();

        $this->assertSame([], $this->scheduled);
    }

    public function testSuccessAfterAnUndeliveredOpenedAlertClosesTheIncidentSilently(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::failed('Rejected.'));
        $notifier = $this->notifier([$email], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $this->runScheduled($this->handler([$email], $this->emailAlerts()));
        $notifier->notifySuccess();

        $this->assertSame([], $this->scheduled);
        $this->assertNull($this->gate->state);
        $this->assertSame([], $this->keptResolved());
    }

    public function testScheduledArgsCarryOnlyNonPersonalIdentifiers(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->andReturn(ChannelDeliveryResult::delivered());
        $notifier = $this->notifier([$email], $this->emailAlerts(1));
        $handler  = $this->handler([$email], $this->emailAlerts(1));
        $args     = [];

        $notifier->notifyFailure($this->error('Mailbox secret-host refused'));
        $args = array_merge($args, array_column($this->scheduled, 'args'));
        $this->runScheduled($handler);
        $this->now += 60;
        $notifier->notifyFailure($this->error('Mailbox secret-host refused'));
        $args = array_merge($args, array_column($this->scheduled, 'args'));
        $this->runScheduled($handler);
        $notifier->notifySuccess();
        $args = array_merge($args, array_column($this->scheduled, 'args'));

        $this->assertSame(
            [
                [FailureNotification::EVENT_OPENED, 'incident-1', 1],
                [FailureNotification::EVENT_REMINDER, 'incident-1', 2],
                [FailureNotification::EVENT_RESOLVED, 'incident-1', 2],
            ],
            $args
        );
        $encoded = (string) json_encode($args);
        foreach (['to@example.com', 'Invoice', 'secret-host', 'wp_mail_failed'] as $personal) {
            $this->assertStringNotContainsString($personal, $encoded);
        }
    }

    public function testQuickRecoveryInTheSameSecondQueuesDistinctEventsThatWordPressCannotDedup(): void
    {
        $notifier = $this->notifier([$this->channel('email')], $this->emailAlerts());

        $notifier->notifyFailure($this->error());
        $notifier->notifySuccess();
        $notifier->notifyFailure($this->error());

        $this->assertSame(
            [FailureNotification::EVENT_OPENED, FailureNotification::EVENT_RESOLVED, FailureNotification::EVENT_OPENED],
            array_map(static fn (array $event): string => $event['args'][0], $this->scheduled)
        );
        $args = array_map(static fn (array $event): string => serialize($event['args']), $this->scheduled);
        $this->assertSame($args, array_values(array_unique($args)));
    }

    public function testAnUndecodableStoredRowCountsAsNoOpenIncident(): void
    {
        $this->gate->state = 'not-an-incident';

        $this->notifier([$this->channel('email')], $this->emailAlerts())->notifyFailure($this->error());

        $this->assertSame([FailureNotification::EVENT_OPENED, 'incident-1', 1], $this->scheduled[0]['args'] ?? null);
        $this->assertSame('incident-1', $this->storedIncident()?->id());
    }

    /**
     * A wp_mail failure with the given message.
     */
    private function error(string $message = 'Failed'): WP_Error
    {
        return new WP_Error('wp_mail_failed', $message, ['to' => ['to@example.com'], 'subject' => 'Invoice']);
    }
}
