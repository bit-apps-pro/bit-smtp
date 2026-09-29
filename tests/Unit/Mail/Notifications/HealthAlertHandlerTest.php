<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\HealthAlertHandler;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Unit\Mail\Notifications\Support\HealthAlertFixtures;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthAlertHandlerTest extends BaseUnitTestCase
{
    use HealthAlertFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testAQueuedUnhealthyEventDeliversAndMarksTheRecord(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true), $store)
            ->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1700000000);

        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $store->records['conn_1']->getLastAlertedState());
        $this->assertNotNull($store->records['conn_1']->getLastAlertedAt());
    }

    public function testAnUnsubscribedEventIsNotDelivered(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);

        $this->handler($dispatcher, $store)->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);

        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    public function testASecondEventForTheSameStateIsDedupedByTheMarker(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store   = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);
        $handler = $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true), $store);

        $handler->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
        $handler->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 2);
    }

    public function testRecoveryDeliversOnceTheConnectionIsHealthyAgain(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::HEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY,
        ])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_RECOVERED, true), $store)
            ->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 1);

        $this->assertSame(HealthNotification::EVENT_RECOVERED, $store->records['conn_1']->getLastAlertedState());
    }

    public function testARecoveryWithoutAnAnnouncedOutageIsSuppressedWhenUnhealthyIsSubscribed(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::HEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_RECOVERED,
        ])]);

        $this->handler($dispatcher, $store)->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 1);
    }

    public function testARecoveryAfterUnhealthyThenAnOauthAlertIsStillDelivered(): void
    {
        $this->preferences([
            HealthNotification::EVENT_UNHEALTHY,
            HealthNotification::EVENT_OAUTH_EXPIRED,
            HealthNotification::EVENT_RECOVERED,
        ]);
        $connection = $this->connection('conn_1', ['token_expires_at' => time() - 10]);
        $store      = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);
        $dispatched = [];
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(static function (NotificationMessage $m) use (&$dispatched): bool {
            $dispatched[] = $m->toArray()['event'];

            return true;
        });
        $handler = $this->handler($dispatcher, $store, [$connection]);

        $handler->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
        $handler->handle(HealthNotification::EVENT_OAUTH_EXPIRED, 'conn_1', 2);
        $store->records['conn_1'] = $store->records['conn_1']->with(['status' => HealthStatus::HEALTHY]);
        $handler->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 3);

        $this->assertSame(
            [HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_OAUTH_EXPIRED, HealthNotification::EVENT_RECOVERED],
            $dispatched
        );
        $this->assertSame(HealthNotification::EVENT_RECOVERED, $store->records['conn_1']->getLastOutageAlert());
    }

    public function testARecoveryAloneIsDeliveredWhenOnlyRecoveriesAreSubscribed(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::HEALTHY])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_RECOVERED, true), $store)
            ->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 1);

        $this->assertSame(HealthNotification::EVENT_RECOVERED, $store->records['conn_1']->getLastAlertedState());
    }

    public function testCooldownSuppressesARepeatAlertWithinTheWindow(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY], 60);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_RECOVERED,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s'),
        ])]);

        $this->handler($dispatcher, $store)->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
    }

    public function testAFlappingConnectionIsNotReAlertedAsUnhealthyWithinTheFlapGuardWhenRepeatIsOff(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_RECOVERED,
            'last_outage_alert'  => HealthNotification::EVENT_RECOVERED,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s', time() - 60),
        ])]);

        $this->handler($dispatcher, $store)->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
    }

    public function testARepeatUnhealthyAlertIsDeliveredOnceTheFlapGuardHasPassed(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED]);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_RECOVERED,
            'last_outage_alert'  => HealthNotification::EVENT_RECOVERED,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s', time() - (HealthAlertHandler::FLAP_GUARD_MINUTES * 60) - 1),
        ])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true), $store)
            ->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
    }

    public function testTheFlapGuardNeverHoldsBackARecovery(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED]);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::HEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY,
            'last_outage_alert'  => HealthNotification::EVENT_UNHEALTHY,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s'),
        ])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_RECOVERED, true), $store)
            ->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 1);
    }

    public function testTheFirstUnhealthyAlertIsNotHeldBackByTheFlapGuard(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_OAUTH_EXPIRED]);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_OAUTH_EXPIRED,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s'),
        ])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true), $store)
            ->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);
    }

    public function testTheRepeatIntervalNeverHoldsBackARecovery(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED], 60);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::HEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY,
            'last_outage_alert'  => HealthNotification::EVENT_UNHEALTHY,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s'),
        ])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_RECOVERED, true), $store)
            ->handle(HealthNotification::EVENT_RECOVERED, 'conn_1', 1);

        $this->assertSame(HealthNotification::EVENT_RECOVERED, $store->records['conn_1']->getLastAlertedState());
    }

    public function testAnEventOvertakenByALaterTransitionIsDroppedAsStale(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::HEALTHY])]);

        $this->handler($dispatcher, $store)->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);

        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    public function testAnEventForADeletedConnectionIsDropped(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();

        $this->handler($dispatcher, $this->store([]))->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_gone', 1);
    }

    public function testMalformedCronArgsAreIgnored(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $handler = $this->handler($dispatcher, $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]));

        $handler->handle('not_an_event', 'conn_1', 1);
        $handler->handle(HealthNotification::EVENT_UNHEALTHY, '', 1);
        $handler->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', '1');
        $handler->handle(null, null, null);
    }

    public function testNoMarkerIsPersistedWhenNoChannelDelivers(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, false), $store)
            ->handle(HealthNotification::EVENT_UNHEALTHY, 'conn_1', 1);

        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    public function testOauthExpiringDeliversWhenTheTokenHasNoRefreshToken(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING]);
        $connection = $this->connection('conn_oauth', ['token_expires_at' => time() + 3600]);
        $store      = $this->store([]);

        $this->handler($this->dispatcherExpecting(HealthNotification::EVENT_OAUTH_EXPIRING, true), $store, [$connection])
            ->handle(HealthNotification::EVENT_OAUTH_EXPIRING, 'conn_oauth', 1);

        $this->assertSame(HealthNotification::EVENT_OAUTH_EXPIRING, $store->records['conn_oauth']->getLastAlertedState());
    }

    public function testAnOauthEventIsDroppedOnceARefreshTokenWasStored(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRED]);
        $connection = $this->connection(
            'conn_oauth',
            ['token_expires_at' => time() - 10],
            ['refresh_token'    => ['source' => 'database', 'value' => 'a-refresh-token']]
        );
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();

        $this->handler($dispatcher, $this->store([]), [$connection])->handle(HealthNotification::EVENT_OAUTH_EXPIRED, 'conn_oauth', 1);
    }

    public function testOauthEventForClassifiesExpiryAgainstTheWarningWindow(): void
    {
        $now = 1700000000;

        $this->assertSame(HealthNotification::EVENT_OAUTH_EXPIRED, HealthAlertHandler::oauthEventFor($this->connection('c', ['token_expires_at' => $now - 1]), $now));
        $this->assertSame(HealthNotification::EVENT_OAUTH_EXPIRING, HealthAlertHandler::oauthEventFor($this->connection('c', ['token_expires_at' => $now + 3600]), $now));
        $this->assertNull(HealthAlertHandler::oauthEventFor($this->connection('c', ['token_expires_at' => $now + 30 * 86400]), $now));
        $this->assertNull(HealthAlertHandler::oauthEventFor($this->connection('c'), $now));
    }
}
