<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

final class FailureNotificationGateOptionState
{
    /**
     * @var array<string,string>|null
     */
    public ?array $marker;

    /**
     * @param array<string,string>|null $marker
     */
    public function __construct(?array $marker)
    {
        $this->marker = $marker;
    }
}

final class FailureNotificationGateConditionalDeleteSpy
{
    public string $options = 'wp_options';

    public int $queryCount = 0;

    /**
     * @var array<int,mixed>
     */
    public array $preparedArguments = [];

    /** @param array<string,string> $snapshot */
    /**
     * @param array<string,string>|null $interleavingMarker
     */
    public function __construct(
        private FailureNotificationGateOptionState $state,
        private array $snapshot,
        private ?array $interleavingMarker = null
    ) {
    }

    /**
     * @param mixed ...$arguments
     */
    public function prepare(string $query, ...$arguments): string
    {
        $this->preparedArguments = $arguments;

        if ($this->interleavingMarker !== null) {
            // A different success reset completes, then the next failure acquires a new marker.
            $this->state->marker = $this->interleavingMarker;
        }

        return $query;
    }

    public function query(string $query): int
    {
        ++$this->queryCount;

        if ($this->state->marker === $this->snapshot) {
            $this->state->marker = null;

            return 1;
        }

        return 0;
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationGateTest extends BaseUnitTestCase
{
    public function testAcquireUsesAtomicAddOptionResult(): void
    {
        Functions\expect('add_option')
            ->once()
            ->with(
                Config::withPrefix('failure_notification_active'),
                Mockery::on(static function (array $marker): bool {
                    return \count($marker) === 1 && isset($marker['started_at']);
                }),
                '',
                'no'
            )
            ->andReturn(true);

        $gate = new FailureNotificationGate();

        $this->assertTrue($gate->acquire());
    }

    public function testResetConditionallyDeletesTheIncidentSnapshot(): void
    {
        $marker          = ['started_at' => 'now'];
        $state           = new FailureNotificationGateOptionState($marker);
        $database        = new FailureNotificationGateConditionalDeleteSpy($state, $marker);
        $hadWpdb         = \array_key_exists('wpdb', $GLOBALS);
        $previousWpdb    = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $database;

        Functions\expect('get_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'), false)
            ->andReturn($marker);
        Functions\when('maybe_serialize')->alias(static fn ($value): string => serialize($value));
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);

        try {
            (new FailureNotificationGate())->reset();

            $this->assertSame(1, $database->queryCount);
            $this->assertSame(
                [Config::withPrefix('failure_notification_active'), serialize($marker)],
                $database->preparedArguments
            );
            $this->assertNull($state->marker);
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $previousWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }

    public function testResetSkipsDeleteWhenNoIncidentActive(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'), false)
            ->andReturn(false);
        Functions\expect('delete_option')->never();

        (new FailureNotificationGate())->reset();
    }

    public function testResetCannotDeleteAMarkerAcquiredAfterItsSnapshot(): void
    {
        $snapshot        = ['started_at' => 'first-streak'];
        $newMarker       = ['started_at' => 'next-streak'];
        $state           = new FailureNotificationGateOptionState($snapshot);
        $database        = new FailureNotificationGateConditionalDeleteSpy($state, $snapshot, $newMarker);
        $hadWpdb         = \array_key_exists('wpdb', $GLOBALS);
        $previousWpdb    = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $database;

        Functions\expect('get_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'), false)
            ->andReturn($snapshot);
        Functions\when('maybe_serialize')->alias(static fn ($value): string => serialize($value));
        Functions\when('delete_option')->alias(static function () use ($state, $newMarker): bool {
            // The old implementation's unconditional delete runs after another request has
            // completed a reset and acquired the next failure streak.
            $state->marker = $newMarker;
            $state->marker = null;

            return true;
        });

        try {
            (new FailureNotificationGate())->reset();

            $this->assertSame($newMarker, $state->marker);
            $this->assertSame(1, $database->queryCount);
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $previousWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }
}
