<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\Deps\BitApps\WPKit\Cache\Repository;
use BitApps\SMTP\Deps\BitApps\WPKit\Cache\Stores\ArrayStore;
use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsCacheTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(static function (string $key, $default) {
            if ($key === 'bit_smtp_logging_enabled') {
                return true;
            }

            return $key === 'bit_smtp_logging_continuity_from' ? '2026-02-01 00:00:00' : $default;
        });
    }

    public function testOverviewHitsTheRepositoryOnceForTwoCallsWithTheSameQuery(): void
    {
        $query   = $this->query(['start' => '2026-03-01T00:00:00-05:00', 'end' => '2026-03-04T00:00:00-05:00', 'bucket' => 'day']);
        $repo    = $this->spyRepository($query);
        $cache   = new Repository(new ArrayStore());
        $service = new MailAnalyticsService($repo, $cache);

        $first  = $service->overview($query);
        $second = $service->overview($query);

        $repo->shouldHaveReceived('summary')->with($query)->once();
        $repo->shouldHaveReceived('timeSeries')->with($query)->once();
        self::assertSame($first, $second);
    }

    public function testOverviewRunsTheRepositoryAgainForADifferentRange(): void
    {
        $first  = $this->query(['start' => '2026-03-01T00:00:00-05:00', 'end' => '2026-03-04T00:00:00-05:00', 'bucket' => 'day']);
        $second = $this->query(['start' => '2026-04-01T00:00:00-05:00', 'end' => '2026-04-04T00:00:00-05:00', 'bucket' => 'day']);
        $repo   = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->twice()->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->twice()->andReturn([]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'source', 10)->twice()->andReturn([]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'connection', 10)->twice()->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->twice()->andReturn(['earliest' => null, 'latest' => null]);
        $cache   = new Repository(new ArrayStore());
        $service = new MailAnalyticsService($repo, $cache);

        $service->overview($first);
        $service->overview($second);

        $repo->shouldHaveReceived('summary')->twice();
    }

    public function testOverviewComputesDirectlyWithoutACacheDependency(): void
    {
        $query   = $this->query(['start' => '2026-03-01T00:00:00-05:00', 'end' => '2026-03-04T00:00:00-05:00', 'bucket' => 'day']);
        $repo    = $this->spyRepository($query);
        $service = new MailAnalyticsService($repo);

        $service->overview($query);
        $service->overview($query);

        $repo->shouldHaveReceived('summary')->with($query)->twice();
    }

    /**
     * @param array<string,mixed> $input
     */
    private function query(array $input): AnalyticsQuery
    {
        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-15T00:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            200
        ))->fromInput($input);

        self::assertInstanceOf(AnalyticsQuery::class, $query);

        return $query;
    }

    /**
     * @return Mockery\MockInterface&MailAnalyticsRepository
     */
    private function spyRepository(AnalyticsQuery $query)
    {
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->with($query)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->andReturn(['earliest' => null, 'latest' => null]);

        return $repo;
    }

    /**
     * @return array<string,int>
     */
    private function summary(): array
    {
        return [
            'total'                => 3,
            'recipient_count'      => 4,
            'accepted'             => 2,
            'failed'               => 1,
            'unknown_source_count' => 1,
            'delivered'            => 1,
            'deferred'             => 0,
            'bounced'              => 0,
            'blocked'              => 0,
            'spam'                 => 0,
            'verified_delivery'    => 1,
        ];
    }
}
