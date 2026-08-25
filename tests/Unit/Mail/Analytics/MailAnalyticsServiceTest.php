<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\EngagementRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailAnalyticsServiceTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(static function ($key, $default) {
            if ($key === 'bit_smtp_logging_enabled') {
                return true;
            }

            return $key === 'bit_smtp_logging_continuity_from' ? '2026-02-01 00:00:00' : $default;
        });
    }

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
            ['utc_hour' => '2026-03-02 05:00:00', 'total' => 3, 'accepted' => 2, 'failed' => 1],
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([
            ['dimension' => 'woocommerce', 'total' => 3],
        ]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([
            ['dimension' => 'conn_primary', 'total' => 3],
        ]);
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);

        $result = (new MailAnalyticsService($repo))->overview($query);

        self::assertSame(3, $result['total']);
        self::assertSame('America/New_York', $result['timezone']);
        self::assertSame(1, $result['unknown_source_count']);
        self::assertStringContainsString('retained Bit SMTP email logs', $result['interpretation']);
        self::assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($result['series'], 'bucket'));
        self::assertSame(0, $result['series'][0]['total']);
        self::assertSame(3, $result['series'][1]['total']);
        self::assertSame(3, $result['acceptance']['denominator']);
    }

    public function testOverviewReportsRetainedRecordBoundsAndDeterministicSiteLocalBusyTimes(): void
    {
        $query = $this->query([
            'start'  => '2026-03-01T00:00:00-05:00',
            'end'    => '2026-03-04T00:00:00-05:00',
            'bucket' => 'day',
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['utc_hour' => '2026-03-01 14:00:00', 'total' => 3],
            ['utc_hour' => '2026-03-02 14:00:00', 'total' => 2],
            ['utc_hour' => '2026-03-03 15:00:00', 'total' => 5],
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn([
            'earliest'                    => '2026-02-01 01:02:03',
            'latest'                      => '2026-03-03 15:00:00',
            'qualified_timestamp_count'   => 8,
            'unqualified_timestamp_count' => 2,
        ]);

        $result = (new MailAnalyticsService($repo))->overview($query);

        self::assertTrue($result['logging_enabled']);
        self::assertSame([
            'earliest' => '2026-02-01T01:02:03+00:00',
            'latest'   => '2026-03-03T15:00:00+00:00',
        ], $result['retained_records']);
        self::assertSame([
            'qualified_records'   => 8,
            'unqualified_records' => 2,
            'interpretation'      => 'Precise time and range analytics exclude retained logs without an explicit UTC timestamp.',
        ], $result['timestamp_coverage']);
        self::assertSame([
            ['hour' => 9, 'label' => '09:00', 'total' => 5],
            ['hour' => 10, 'label' => '10:00', 'total' => 5],
        ], \array_slice($result['busiest_hours'], 0, 2));
        self::assertSame([
            ['weekday' => 2, 'label' => 'Tuesday', 'total' => 5],
            ['weekday' => 7, 'label' => 'Sunday', 'total' => 3],
            ['weekday' => 1, 'label' => 'Monday', 'total' => 2],
        ], \array_slice($result['busiest_weekdays'], 0, 3));
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

        $result = (new MailAnalyticsService($repo))->deliverability($query);

        self::assertSame(10, $result['acceptance']['denominator']);
        self::assertSame(5, $result['delivery']['denominator']);
        self::assertSame(5, $result['delivery']['unknown']);
        self::assertSame(60.0, $result['delivery']['delivered_rate']);
    }

    public function testDeliverabilityKeepsProviderAcceptanceAndPendingOutOfTheVerifiedDenominator(): void
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
            'accepted_delivery'    => 2,
            'pending_delivery'     => 1,
            'verified_delivery'    => 5,
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);

        $result = (new MailAnalyticsService($repo))->deliverability($query);

        self::assertSame(5, $result['delivery']['denominator']);
        self::assertSame(2, $result['delivery']['accepted']);
        self::assertSame(1, $result['delivery']['pending']);
        self::assertSame(2, $result['delivery']['unknown']);
        self::assertSame(10, array_sum([
            $result['delivery']['delivered'],
            $result['delivery']['delayed'],
            $result['delivery']['bounced'],
            $result['delivery']['blocked'],
            $result['delivery']['spam'],
            $result['delivery']['accepted'],
            $result['delivery']['pending'],
            $result['delivery']['unknown'],
        ]));
    }

    public function testEngagementSeparatesHumanFromAutomatedFiresAndRatesOverDeliveredOrAccepted(): void
    {
        $query = $this->query([]);
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn(array_merge($this->summary(), [
            'total'             => 12,
            'verified_delivery' => 6,
            'accepted_delivery' => 2,
        ]));
        $engagement = Mockery::mock(EngagementRepository::class);
        $engagement->shouldReceive('engagement')->once()->with($query)->andReturn([
            'open_hits'            => 10,
            'open_automated_hits'  => 4,
            'open_rows'            => 5,
            'open_human_logs'      => 4,
            'click_hits'           => 3,
            'click_automated_hits' => 1,
            'click_rows'           => 2,
            'click_human_logs'     => 2,
        ]);

        $result = (new MailAnalyticsService($repo, null, $engagement))->engagement($query);

        self::assertSame(['total' => 10, 'automated' => 4, 'human' => 6, 'unique' => 5], $result['opens']);
        self::assertSame(['total' => 3, 'automated' => 1, 'human' => 2, 'unique' => 2], $result['clicks']);
        self::assertSame(['engaged_logs' => 4, 'denominator' => 8, 'rate' => 50.0], $result['open_rate']);
        self::assertSame(['engaged_logs' => 2, 'denominator' => 8, 'rate' => 25.0], $result['click_rate']);
        self::assertStringContainsString('per message, not per recipient', $result['engagement_interpretation']);
    }

    public function testEngagementReturnsTheStableDisabledLoggingErrorWithoutTouchingEitherRepository(): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default) {
            return $key === 'bit_smtp_logging_enabled' ? false : $default;
        });
        $query      = $this->query([]);
        $repo       = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');
        $engagement = Mockery::mock(EngagementRepository::class);
        $engagement->shouldNotReceive('engagement');

        $result = (new MailAnalyticsService($repo, null, $engagement))->engagement($query);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
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
            static fn (int $n): array => ['pattern' => "Receipt pattern {$n}", 'total' => 1],
            range(1, 12)
        ));
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);

        $result = (new MailAnalyticsService($repo))->plugin($query);

        self::assertCount(10, $result['subject_patterns']);
        self::assertSame('Receipt pattern 1', $result['subject_patterns'][0]['pattern']);
        self::assertArrayNotHasKey('subject', $result['subject_patterns'][0]);
    }

    public function testPluginReportsSiteLocalBusyTimesWhileKeepingTheNotificationProxyInterpretation(): void
    {
        $query = $this->query(['plugin' => 'woocommerce']);
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['utc_hour' => '2026-03-01 14:00:00', 'total' => 3],
            ['utc_hour' => '2026-03-02 15:00:00', 'total' => 4],
        ]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'routing_type', 10)->andReturn([]);
        $repo->shouldReceive('subjectCounts')->once()->with($query)->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);

        $result = (new MailAnalyticsService($repo))->plugin($query);

        self::assertSame([
            ['hour' => 10, 'label' => '10:00', 'total' => 4],
            ['hour' => 9, 'label' => '09:00', 'total' => 3],
        ], $result['busiest_hours']);
        self::assertSame([
            ['weekday' => 1, 'label' => 'Monday', 'total' => 4],
            ['weekday' => 7, 'label' => 'Sunday', 'total' => 3],
        ], $result['busiest_weekdays']);
        self::assertStringContainsString('only a proxy', $result['proxy_interpretation']);
        self::assertStringContainsString('order-related activity', $result['proxy_interpretation']);
    }

    public function testAnalyticsReturnTheStableDisabledLoggingError(): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default) {
            return $key === 'bit_smtp_logging_enabled' ? false : $default;
        });
        $query = $this->query([]);
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldNotReceive('summary');
        $repo->shouldNotReceive('timeSeries');
        $repo->shouldNotReceive('groups');
        $repo->shouldNotReceive('retainedRecordBounds');

        $result = (new MailAnalyticsService($repo))->overview($query);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('bit_smtp_logging_disabled', $result->get_error_code());
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
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn([
            'earliest' => '2026-02-01 00:00:00',
            'latest'   => '2026-03-02 00:00:00',
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 100)->andReturn([['dimension' => 'wpforms-lite', 'total' => 19, 'failed' => 4]]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 100)->andReturn([['dimension' => 'conn_primary', 'total' => 19, 'failed' => 4]]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);
        $repo->shouldReceive('timeSeries')->with($query)->andReturn([
            ['utc_hour' => '2026-03-01 14:00:00', 'total' => 19],
        ]);
        $repo->shouldReceive('timeSeries')->with(Mockery::on(static fn (AnalyticsQuery $candidate): bool => $candidate->start()->format(DATE_ATOM) === $prior->start()->format(DATE_ATOM)))->andReturn([
            ['utc_hour' => '2026-02-28 15:00:00', 'total' => 19],
        ]);

        $result = (new MailAnalyticsService($repo))->anomalies($query);

        self::assertSame(19, $result['current']['total']);
        self::assertNotEmpty($result['observations']);
        self::assertNotContains('failure_rate_change', array_column($result['observations'], 'type'));
        self::assertNotContains('hourly_distribution_shift', array_column($result['observations'], 'type'));
        self::assertNotContains('weekday_distribution_shift', array_column($result['observations'], 'type'));
    }

    public function testAnomaliesReportDeterministicSiteLocalHourlyAndWeekdayDistributionShiftsWithCounts(): void
    {
        $query = $this->query([
            'start' => '2026-03-02T00:00:00-05:00',
            'end'   => '2026-03-03T00:00:00-05:00',
        ]);
        $prior = $query->priorPeriod();
        $repo  = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn(array_merge($this->summary(), [
            'total' => 40, 'accepted' => 36, 'failed' => 4,
        ]));
        $repo->shouldReceive('summary')->once()->with(Mockery::on(static fn (AnalyticsQuery $candidate): bool => $candidate->start()->format(DATE_ATOM) === $prior->start()->format(DATE_ATOM)))->andReturn(array_merge($this->summary(), [
            'total' => 40, 'accepted' => 38, 'failed' => 2,
        ]));
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn([
            'earliest' => '2026-02-01 00:00:00',
            'latest'   => '2026-03-03 00:00:00',
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 100)->andReturn([]);
        $repo->shouldReceive('groups')->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['utc_hour' => '2026-03-02 14:00:00', 'total' => 20],
            ['utc_hour' => '2026-03-02 15:00:00', 'total' => 20],
        ]);
        $repo->shouldReceive('timeSeries')->once()->with(Mockery::on(static fn (AnalyticsQuery $candidate): bool => $candidate->start()->format(DATE_ATOM) === $prior->start()->format(DATE_ATOM)))->andReturn([
            ['utc_hour' => '2026-03-01 14:00:00', 'total' => 40],
        ]);

        $result = (new MailAnalyticsService($repo))->anomalies($query);

        self::assertSame([
            [
                'type'                     => 'hourly_distribution_shift',
                'hour'                     => 9,
                'current'                  => 20,
                'prior'                    => 40,
                'current_percentage'       => 50.0,
                'prior_percentage'         => 100.0,
                'percentage_point_change'  => -50.0,
            ],
            [
                'type'                     => 'hourly_distribution_shift',
                'hour'                     => 10,
                'current'                  => 20,
                'prior'                    => 0,
                'current_percentage'       => 50.0,
                'prior_percentage'         => 0.0,
                'percentage_point_change'  => 50.0,
            ],
            [
                'type'                     => 'weekday_distribution_shift',
                'weekday'                  => 1,
                'current'                  => 40,
                'prior'                    => 0,
                'current_percentage'       => 100.0,
                'prior_percentage'         => 0.0,
                'percentage_point_change'  => 100.0,
            ],
            [
                'type'                     => 'weekday_distribution_shift',
                'weekday'                  => 7,
                'current'                  => 0,
                'prior'                    => 40,
                'current_percentage'       => 0.0,
                'prior_percentage'         => 100.0,
                'percentage_point_change'  => -100.0,
            ],
        ], array_values(array_filter(
            $result['observations'],
            static fn (array $observation): bool => \in_array($observation['type'], ['hourly_distribution_shift', 'weekday_distribution_shift'], true)
        )));
    }

    public function testAnomaliesCompareSparseHistoryWhenConfiguredRetentionAndContinuityCoverThePriorPeriod(): void
    {
        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            20
        ))->fromInput([
            'start' => '2026-03-25T00:00:00+00:00',
            'end'   => '2026-04-01T00:00:00+00:00',
        ]);
        self::assertInstanceOf(AnalyticsQuery::class, $query);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->twice()->andReturn($this->summary());
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn([
            'earliest' => '2026-03-20 00:00:00',
            'latest'   => '2026-04-01 00:00:00',
        ]);
        $repo->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);
        $repo->shouldReceive('timeSeries')->twice()->with(Mockery::type(AnalyticsQuery::class))->andReturn([]);

        $result = (new MailAnalyticsService($repo))->anomalies($query);

        self::assertTrue($result['comparison_coverage']['complete']);
        self::assertSame([[
            'type'              => 'volume_change',
            'current'           => 3,
            'prior'             => 3,
            'percentage_change' => 0.0,
        ]], $result['observations']);
        self::assertSame('2026-03-20T00:00:00+00:00', $result['comparison_coverage']['retained_from']);
        self::assertSame('2026-03-12T00:00:00+00:00', $result['comparison_coverage']['configured_retained_from']);
    }

    public function testAnomaliesCompareContinuousZeroVolumeWindowsWithoutAnEarliestEvent(): void
    {
        $query = $this->query([
            'start' => '2026-03-10T00:00:00+00:00',
            'end'   => '2026-03-11T00:00:00+00:00',
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->twice()->andReturn($this->summary());
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);
        $repo->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $repo->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);
        $repo->shouldReceive('timeSeries')->twice()->with(Mockery::type(AnalyticsQuery::class))->andReturn([]);

        $result = (new MailAnalyticsService($repo))->anomalies($query);

        self::assertTrue($result['comparison_coverage']['complete']);
        self::assertNull($result['comparison_coverage']['retained_from']);
        self::assertSame([[
            'type'              => 'volume_change',
            'current'           => 3,
            'prior'             => 3,
            'percentage_change' => 0.0,
        ]], $result['observations']);
    }

    public function testAnomaliesRequireBothQualifiedRetentionAndPostResumeContinuityCoverage(): void
    {
        $continuityFrom = '2026-03-09 12:00:00';
        Functions\when('get_option')->alias(static function (string $key, $default) use (&$continuityFrom) {
            if ($key === 'bit_smtp_logging_enabled') {
                return true;
            }

            return $key === 'bit_smtp_logging_continuity_from' ? $continuityFrom : $default;
        });
        $query = $this->query([
            'start' => '2026-03-10T00:00:00+00:00',
            'end'   => '2026-03-11T00:00:00+00:00',
        ]);
        $bounds = [
            'earliest'                    => '2026-03-01 00:00:00',
            'latest'                      => '2026-03-11 00:00:00',
            'qualified_timestamp_count'   => 40,
            'unqualified_timestamp_count' => 5,
        ];

        $incomplete = Mockery::mock(MailAnalyticsRepository::class);
        $incomplete->shouldReceive('summary')->twice()->andReturn($this->summary());
        $incomplete->shouldReceive('retainedRecordBounds')->once()->andReturn($bounds);
        $incomplete->shouldNotReceive('groups');
        $incomplete->shouldNotReceive('timeSeries');
        $withoutPostResumeHistory = (new MailAnalyticsService($incomplete))->anomalies($query);

        self::assertFalse($withoutPostResumeHistory['comparison_coverage']['complete']);
        self::assertSame('2026-03-09T12:00:00+00:00', $withoutPostResumeHistory['comparison_coverage']['continuity_from']);
        self::assertSame([], $withoutPostResumeHistory['observations']);

        // Once both equal comparison windows begin after the recorded resume, qualified retained
        // timestamps and continuity together cover the comparison.
        $continuityFrom = '2026-03-01 00:00:00';
        $complete       = Mockery::mock(MailAnalyticsRepository::class);
        $complete->shouldReceive('summary')->twice()->andReturn($this->summary());
        $complete->shouldReceive('retainedRecordBounds')->once()->andReturn($bounds);
        $complete->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'source', 100)->andReturn([]);
        $complete->shouldReceive('groups')->twice()->with(Mockery::type(AnalyticsQuery::class), 'connection', 100)->andReturn([]);
        $complete->shouldReceive('timeSeries')->twice()->with(Mockery::type(AnalyticsQuery::class))->andReturn([]);
        $withPostResumeHistory = (new MailAnalyticsService($complete))->anomalies($query);

        self::assertTrue($withPostResumeHistory['comparison_coverage']['complete']);
        self::assertSame(40, $withPostResumeHistory['timestamp_coverage']['qualified_records']);
        self::assertSame(5, $withPostResumeHistory['timestamp_coverage']['unqualified_records']);
    }

    public function testConvertsUtcHoursInPhpForSpringForwardAndFallBackWithoutMergingRepeatedHours(): void
    {
        $query = $this->query([
            'start'  => '2026-11-01T00:00:00-04:00',
            'end'    => '2026-11-01T03:00:00-05:00',
            'bucket' => 'hour',
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['utc_hour' => '2026-11-01 05:00:00', 'total' => 1, 'accepted' => 1, 'failed' => 0],
            ['utc_hour' => '2026-11-01 06:00:00', 'total' => 2, 'accepted' => 2, 'failed' => 0],
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);

        $result = (new MailAnalyticsService($repo))->overview($query);

        self::assertSame('2026-11-01T01:00:00-04:00', $result['series'][1]['bucket']);
        self::assertSame('2026-11-01T01:00:00-05:00', $result['series'][2]['bucket']);
        self::assertSame('2026-11-01 01:00', $result['series'][1]['label']);
        self::assertSame('2026-11-01 01:00', $result['series'][2]['label']);
        self::assertSame(1, $result['series'][1]['total']);
        self::assertSame(2, $result['series'][2]['total']);
    }

    public function testSkipsTheNonexistentSpringForwardLocalHourWhenFillingUtcBuckets(): void
    {
        $query = $this->query([
            'start'  => '2026-03-08T00:00:00-05:00',
            'end'    => '2026-03-08T04:00:00-04:00',
            'bucket' => 'hour',
        ]);
        $repo = Mockery::mock(MailAnalyticsRepository::class);
        $repo->shouldReceive('summary')->once()->with($query)->andReturn($this->summary());
        $repo->shouldReceive('timeSeries')->once()->with($query)->andReturn([
            ['utc_hour' => '2026-03-08 07:00:00', 'total' => 1, 'accepted' => 1, 'failed' => 0],
        ]);
        $repo->shouldReceive('groups')->with($query, 'source', 10)->andReturn([]);
        $repo->shouldReceive('groups')->with($query, 'connection', 10)->andReturn([]);
        $repo->shouldReceive('retainedRecordBounds')->once()->andReturn(['earliest' => null, 'latest' => null]);

        $result = (new MailAnalyticsService($repo))->overview($query);

        self::assertSame(['2026-03-08 00:00', '2026-03-08 01:00', '2026-03-08 03:00'], array_column($result['series'], 'label'));
        self::assertSame('2026-03-08T03:00:00-04:00', $result['series'][2]['bucket']);
        self::assertSame(1, $result['series'][2]['total']);
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
