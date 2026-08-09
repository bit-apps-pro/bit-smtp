<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

final class FailureNotificationGateDatabaseSpy
{
    public string $options = 'wp_options';

    public int $insertCount = 0;

    public int $getVarCount = 0;

    public int $queryCount = 0;

    /**
     * @var array<int,bool>
     */
    public array $suppressErrorCalls = [];

    /**
     * @var array<int,array<int,mixed>>
     */
    public array $preparedArguments = [];

    /**
     * @var array<int,array<string,string>>
     */
    public array $insertData = [];

    /**
     * @var array<int,string>
     */
    public array $insertTables = [];

    public ?string $interleavingIncidentId = null;

    public function __construct(public ?string $incidentId = null)
    {
    }

    /**
     * @param array<string,string> $data
     * @param array<int,string>    $format
     */
    public function insert(string $table, array $data, array $format): int|false
    {
        ++$this->insertCount;
        $this->insertData[]   = $data;
        $this->insertTables[] = $table;

        if ($this->incidentId !== null) {
            return false;
        }

        $this->incidentId = $data['option_value'];

        return 1;
    }

    public function suppress_errors(bool $suppress): bool
    {
        $this->suppressErrorCalls[] = $suppress;

        return false;
    }

    /**
     * @param mixed ...$arguments
     */
    public function prepare(string $query, ...$arguments): string
    {
        $this->preparedArguments[] = $arguments;

        return $query;
    }

    public function get_var(string $query): ?string
    {
        ++$this->getVarCount;

        return $this->incidentId;
    }

    public function query(string $query): int
    {
        ++$this->queryCount;

        if ($this->interleavingIncidentId !== null) {
            $this->incidentId = $this->interleavingIncidentId;
        }

        $incidentId = $this->preparedArguments[\count($this->preparedArguments) - 1][1] ?? null;

        if ($this->incidentId === $incidentId) {
            $this->incidentId = null;

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
    public function testAcquireUsesPlainInsertInsteadOfAddOptionCachePreflight(): void
    {
        // This models a stale persistent `notoptions` cache: core add_option() would perform
        // its preflight and its ON DUPLICATE KEY UPDATE could report success. The option row
        // already exists, so the database's plain INSERT must be the only authority.
        $database = new FailureNotificationGateDatabaseSpy('active-incident');

        Functions\expect('add_option')->never();
        Functions\expect('get_option')->never();
        Functions\expect('wp_generate_uuid4')->once()->andReturn('competing-incident');
        Functions\expect('wp_cache_set')->never();
        Functions\expect('wp_cache_delete')->once()->with(Config::withPrefix('failure_notification_active'), 'options')->andReturn(true);
        Functions\expect('wp_cache_delete')->once()->with('notoptions', 'options')->andReturn(true);

        $this->withDatabase($database, function (): void {
            $this->assertFalse((new FailureNotificationGate())->acquire());
        });

        $this->assertSame('active-incident', $database->incidentId);
        $this->assertSame(1, $database->insertCount);
        $this->assertSame(['wp_options'], $database->insertTables);
        $this->assertSame([true, false], $database->suppressErrorCalls);
        $this->assertSame([[
            'option_name'  => Config::withPrefix('failure_notification_active'),
            'option_value' => 'competing-incident',
            'autoload'     => 'no',
        ]], $database->insertData);
    }

    public function testOnlyOneConcurrentFirstFailureCanAcquireTheGate(): void
    {
        $database = new FailureNotificationGateDatabaseSpy();

        Functions\when('wp_generate_uuid4')->alias(static function (): string {
            static $next = 0;

            return 'incident-' . ++$next;
        });
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->withDatabase($database, function (): void {
            $first  = new FailureNotificationGate();
            $second = new FailureNotificationGate();

            $this->assertTrue($first->acquire());
            $this->assertFalse($second->acquire());
        });

        $this->assertSame(2, $database->insertCount);
        $this->assertSame('incident-1', $database->incidentId);
        $this->assertSame([
            [
                'option_name'  => Config::withPrefix('failure_notification_active'),
                'option_value' => 'incident-1',
                'autoload'     => 'no',
            ],
            [
                'option_name'  => Config::withPrefix('failure_notification_active'),
                'option_value' => 'incident-2',
                'autoload'     => 'no',
            ],
        ], $database->insertData);
    }

    public function testResetConditionallyDeletesTheDatabaseIncidentSnapshot(): void
    {
        $database = new FailureNotificationGateDatabaseSpy('incident-1');

        Functions\expect('get_option')->never();
        Functions\expect('wp_cache_set')->never();
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->withDatabase($database, function (): void {
            (new FailureNotificationGate())->reset();
        });

        $this->assertSame(1, $database->getVarCount);
        $this->assertSame(1, $database->queryCount);
        $this->assertSame([
            [Config::withPrefix('failure_notification_active')],
            [Config::withPrefix('failure_notification_active'), 'incident-1'],
        ], $database->preparedArguments);
        $this->assertNull($database->incidentId);
    }

    public function testResetSkipsDeleteWhenNoIncidentIsActiveInTheDatabase(): void
    {
        $database = new FailureNotificationGateDatabaseSpy();

        Functions\expect('get_option')->never();
        Functions\expect('wp_cache_delete')->once()->with(Config::withPrefix('failure_notification_active'), 'options')->andReturn(true);
        Functions\expect('wp_cache_delete')->once()->with('notoptions', 'options')->andReturn(true);

        $this->withDatabase($database, function (): void {
            (new FailureNotificationGate())->reset();
        });

        $this->assertSame(1, $database->getVarCount);
        $this->assertSame(0, $database->queryCount);
    }

    public function testStaleResetCannotDeleteANewIncidentThatStartsInTheSameSecond(): void
    {
        // The old and new streak deliberately share a timestamp. The opaque incident IDs,
        // rather than the clock, distinguish their conditional database operations.
        $database                         = new FailureNotificationGateDatabaseSpy('incident-first');
        $database->interleavingIncidentId = 'incident-next';

        Functions\when('wp_cache_delete')->justReturn(true);

        $this->withDatabase($database, function (): void {
            (new FailureNotificationGate())->reset();
        });

        $this->assertSame(1, $database->queryCount);
        $this->assertSame('incident-next', $database->incidentId);
        $this->assertSame(
            [Config::withPrefix('failure_notification_active'), 'incident-first'],
            $database->preparedArguments[1]
        );
    }

    public function testResetUsesDatabaseSnapshotDespiteAStalePersistentOptionCache(): void
    {
        // The cache still contains an old incident, but reset must select the current row from
        // wp_options directly before deciding what to delete.
        $persistentCacheIncidentId = 'stale-cache-incident';
        $database                  = new FailureNotificationGateDatabaseSpy('database-incident');

        Functions\expect('get_option')->never();
        Functions\expect('wp_cache_get')->never();
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->withDatabase($database, function () use ($persistentCacheIncidentId): void {
            $this->assertSame('stale-cache-incident', $persistentCacheIncidentId);
            (new FailureNotificationGate())->reset();
        });

        $this->assertNull($database->incidentId);
        $this->assertSame(
            [Config::withPrefix('failure_notification_active'), 'database-incident'],
            $database->preparedArguments[1]
        );
    }

    public function testStaleResetInvalidatesCachesWithoutWritingANotoptionsMissForNewIncident(): void
    {
        // A newly acquired row appears after reset's database snapshot. Writing `notoptions`
        // here would hide that row from another request's persistent object cache.
        $database                         = new FailureNotificationGateDatabaseSpy('incident-first');
        $database->interleavingIncidentId = 'incident-next';

        Functions\expect('wp_cache_set')->never();
        Functions\expect('wp_cache_delete')->once()->with(Config::withPrefix('failure_notification_active'), 'options')->andReturn(true);
        Functions\expect('wp_cache_delete')->once()->with('notoptions', 'options')->andReturn(true);

        $this->withDatabase($database, function (): void {
            (new FailureNotificationGate())->reset();
        });

        $this->assertSame('incident-next', $database->incidentId);
    }

    /**
     * @param callable():void $callback
     */
    private function withDatabase(FailureNotificationGateDatabaseSpy $database, callable $callback): void
    {
        $hadWpdb         = \array_key_exists('wpdb', $GLOBALS);
        $previousWpdb    = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $database;

        try {
            $callback();
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $previousWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }
}
