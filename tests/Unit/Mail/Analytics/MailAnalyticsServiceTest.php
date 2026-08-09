<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Mail\Analytics\SubjectPatternNormalizer;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailAnalyticsServiceTest extends BaseUnitTestCase
{
    public function testOverviewFillsEmptyDailyPeriodsAndCarriesTheRequiredRetainedLogMetadata(): void
    {
        $query = $this->query([
            'start'  => '2026-03-01T00:00:00-05:00',
            'end'    => '2026-03-04T00:00:00-05:00',
            'bucket' => 'day',
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['bucket' => '2026-03-02', 'total' => 3, 'accepted' => 2, 'failed' => 1],
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([
            ['dimension' => 'woocommerce', 'total' => 3],
        ]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([
            ['dimension' => 'conn_primary', 'total' => 3],
        ]);

        $result = (new MailAnalyticsService($repo, new SubjectPatternNormalizer()))->overview($query);

        self::assertSame(3, $result['total']);
        self::assertSame('America/New_York', $result['timezone']);
        self::assertSame(1, $result['unknown_source_count']);
        self::assertStringContainsString('retained Bit SMTP email logs', $result['interpretation']);
        self::assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($result['series'], 'bucket'));
        self::assertSame(0, $result['series'][0]['total']);
        self::assertSame(3, $result['series'][1]['total']);
        self::assertSame(3, $result['acceptance']['denominator']);
    }

    public function testDeliverabilitySeparatesAcceptanceFromConfirmedDeliveryDenominators(): void
    {
        $query = $this->query([]);
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn([
            'total'                => 10,
            'recipient_count'      => 10,
            'accepted'             => 8,
            'failed'               => 2,
            'unknown_source_count' => 4,
            'delivered'            => 3,
            'deferred'             => 1,
            'bounced'              => 1,
            'blocked'              => 0,
            'spam'                 => 0,
            'verified_delivery'    => 5,
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);

        $result = (new MailAnalyticsService($repo, new SubjectPatternNormalizer()))->deliverability($query);

        self::assertSame(10, $result['acceptance']['denominator']);
        self::assertSame(5, $result['delivery']['denominator']);
        self::assertSame(5, $result['delivery']['unknown']);
        self::assertSame(60.0, $result['delivery']['delivered_rate']);
    }

    public function testPluginGroupsNormalizedSubjectsAndReturnsAtMostTenPatterns(): void
    {
        $query = $this->query(['plugin' => 'woocommerce']);
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'routing_type', 10)->andReturn([]);
        $repo->shouldReceive('subjectCounts')->once()->with($query)->andReturn(array_map(
            static fn (int $n): array => ['subject' => "Order {$n}12345 for customer@example.test", 'total' => 1],
            range(1, 12)
        ));

        $result = (new MailAnalyticsService($repo, new SubjectPatternNormalizer()))->plugin($query);

        self::assertCount(1, $result['subject_patterns']);
        self::assertSame('Order <number> for <email>', $result['subject_patterns'][0]['pattern']);
        self::assertSame(12, $result['subject_patterns'][0]['total']);
    }

    public function testAnomaliesCompareThePriorEqualPeriodAndSuppressRateChangesBelowTwentyMessages(): void
    {
        $query = $this->query([
            'start' => '2026-03-01T00:00:00+00:00',
            'end'   => '2026-03-02T00:00:00+00:00',
        ]);
        $prior = $query->priorPeriod();
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn(array_merge($this->summary(), [
            'total' => 19, 'accepted' => 15, 'failed' => 4,
        ]));
        $repo->shouldReceive('summary')->once()->with(Mockery::on(static fn (AnalyticsQuery $candidate): bool => $candidate->start()->format(DATE_ATOM) === $prior->start()->format(DATE_ATOM)))->andReturn(array_merge($this->summary(), [
            'total' => 19, 'accepted' => 18, 'failed' => 1,
        ]));
        $repo->shouldReceive('groups')->with($query, 'source', 100)->andReturn([['dimension' => 'wpforms-lite', 'total' => 19, 'failed' => 4]]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 100)->andReturn([['dimension' => 'conn_primary', 'total' => 19, 'failed' => 4]]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);

        $result = (new MailAnalyticsService($repo, new SubjectPatternNormalizer()))->anomalies($query);

        self::assertSame(19, $result['current']['total']);
        self::assertNotEmpty($result['observations']);
        self::assertNotContains('failure_rate_change', array_column($result['observations'], 'type'));
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
