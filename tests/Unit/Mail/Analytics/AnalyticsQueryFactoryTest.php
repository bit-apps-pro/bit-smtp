<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsQueryFactoryTest extends BaseUnitTestCase
{
    public function testDefaultsToThirtyDaysEndingAtTheInjectedNow(): void
    {
        $factory = $this->factory('2026-03-15T16:30:00+00:00', 45);

        $query = $factory->fromInput([]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('America/New_York', $query->timezone()->getName());
        self::assertSame('2026-02-13T17:30:00+00:00', $query->start()->format(DATE_ATOM));
        self::assertSame('2026-03-15T16:30:00+00:00', $query->end()->format(DATE_ATOM));
        self::assertSame('day', $query->bucket());
    }

    public function testConvertsSiteLocalDstBoundariesToUtcWithoutChangingTheCalendarRange(): void
    {
        $query = $this->factory('2026-03-10T00:00:00+00:00', 30)->fromInput([
            'start'  => '2026-03-08T00:00:00-05:00',
            'end'    => '2026-03-09T00:00:00-04:00',
            'bucket' => 'hour',
        ]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);
        self::assertSame('2026-03-08T05:00:00+00:00', $query->start()->format(DATE_ATOM));
        self::assertSame('2026-03-09T04:00:00+00:00', $query->end()->format(DATE_ATOM));
        self::assertSame('hour', $query->bucket());
    }

    public function testRejectsMalformedIsoDatesAndRangesBeyondConfiguredRetention(): void
    {
        $factory = $this->factory('2026-04-01T00:00:00+00:00', 7);

        $malformed = $factory->fromInput(['start' => 'not-a-date']);
        $tooLong   = $factory->fromInput([
            'start' => '2026-03-24T00:00:00+00:00',
            'end'   => '2026-04-01T00:00:00+00:00',
        ]);

        self::assertInstanceOf(WP_Error::class, $malformed);
        self::assertSame('bit_smtp_invalid_analytics_range', $malformed->get_error_code());
        self::assertInstanceOf(WP_Error::class, $tooLong);
        self::assertSame('bit_smtp_invalid_analytics_range', $tooLong->get_error_code());
    }

    public function testUsesOnlyTheFixedBucketsAndValidatedExactFilters(): void
    {
        $factory = $this->factory('2026-04-01T00:00:00+00:00', 200);

        $weekly = $factory->fromInput([
            'start'         => '2026-01-01T00:00:00+00:00',
            'end'           => '2026-04-01T00:00:00+00:00',
            'plugin'        => 'theme:storefront',
            'connection_id' => 'conn_primary-1',
        ]);
        $invalid = $factory->fromInput(['bucket' => 'month']);

        self::assertInstanceOf(AnalyticsQuery::class, $weekly);
        self::assertSame('week', $weekly->bucket());
        self::assertSame('theme:storefront', $weekly->plugin());
        self::assertSame('conn_primary-1', $weekly->connectionId());
        self::assertInstanceOf(WP_Error::class, $invalid);
        self::assertSame('bit_smtp_invalid_analytics_input', $invalid->get_error_code());
    }

    private function factory(string $now, int $retentionDays): AnalyticsQueryFactory
    {
        return new AnalyticsQueryFactory(
            new DateTimeImmutable($now),
            new DateTimeZone('America/New_York'),
            $retentionDays
        );
    }
}
