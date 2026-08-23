<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Cache\Repository;
use BitApps\SMTP\Deps\BitApps\WPKit\Cache\Stores\ArrayStore;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\AnalyticsController;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * MailAnalyticsService is `final`, so Mockery cannot subclass it for a strictly-typed constructor
 * argument (see AnalyticsCacheTest for the same constraint on the service itself). These tests
 * instead build a real MailAnalyticsService over a mocked MailAnalyticsRepository and an in-memory
 * cache, which keeps every scenario off the real database while still exercising the controller's
 * actual request/response wiring.
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsControllerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        // The base Request constructor always reads $_GET through wp_unslash(), even when empty.
        Functions\when('wp_unslash')->returnArg(1);
    }

    public function testOverviewReturnsTheServiceResultOnValidInput(): void
    {
        $this->stubLoggingEnabled(true);

        $request           = new Request();
        $request['start']  = '2026-03-01T00:00:00+00:00';
        $request['end']    = '2026-03-04T00:00:00+00:00';
        $request['bucket'] = 'day';

        $controller = new AnalyticsController($this->service($this->repository()));

        $controller->overview($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['acceptance']['accepted']);
        $this->assertSame(1, $data['acceptance']['failed']);
        $this->assertTrue($data['logging_enabled']);
        $this->assertSame([], $data['top_sources']);
    }

    public function testDeliverabilityReturnsTheDocumentedLoggingOffResponseWhenLoggingIsDisabled(): void
    {
        $this->stubLoggingEnabled(false);

        // loggingDisabledError() short-circuits before any repository call; an unconfigured Mockery
        // double throws on any method call, so this also proves the repository is never touched.
        $repository = Mockery::mock(MailAnalyticsRepository::class);
        $controller = new AnalyticsController($this->service($repository));

        $controller->deliverability(new Request());

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame('bit_smtp_logging_disabled', Response::getCode());
        $this->assertSame(200, Response::getHttpStatusCode());
    }

    public function testAnomaliesReturns422WhenTheRequestedRangeIsInvalid(): void
    {
        $this->stubLoggingEnabled(true);

        $request          = new Request();
        $request['start'] = 'not-a-date';

        // The query factory rejects the input before the service (and therefore the repository) is
        // ever reached.
        $repository = Mockery::mock(MailAnalyticsRepository::class);
        $controller = new AnalyticsController($this->service($repository));

        $controller->anomalies($request);

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame('bit_smtp_invalid_analytics_range', Response::getCode());
        $this->assertSame(422, Response::getHttpStatusCode());
    }

    public function testDeliverabilityReturnsTheServiceResultOnValidInput(): void
    {
        $this->stubLoggingEnabled(true);

        $request           = new Request();
        $request['start']  = '2026-03-01T00:00:00+00:00';
        $request['end']    = '2026-03-04T00:00:00+00:00';
        $request['bucket'] = 'day';

        $controller = new AnalyticsController($this->service($this->repository()));

        $controller->deliverability($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(2, $data['acceptance']['accepted']);
        $this->assertSame(1, $data['delivery']['delivered']);
        $this->assertSame([], $data['sources']);
        $this->assertSame([], $data['connections']);
    }

    public function testAnomaliesReturnsTheServiceResultOnValidInput(): void
    {
        $this->stubLoggingEnabled(true);

        $request           = new Request();
        $request['start']  = '2026-03-01T00:00:00+00:00';
        $request['end']    = '2026-03-04T00:00:00+00:00';
        $request['bucket'] = 'day';

        $controller = new AnalyticsController($this->service($this->repository()));

        $controller->anomalies($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(3, $data['current']['total']);
        $this->assertSame(3, $data['prior']['total']);
        $this->assertArrayHasKey('observations', $data);
    }

    public function testOverviewReturns500WhenTheServiceReturnsANonLoggingDisabledError(): void
    {
        $this->stubLoggingEnabled(true);

        // A repository failure (e.g. the aggregate query itself erroring) is the "anything else"
        // case the controller docblock maps to 500, as opposed to the logging-disabled short circuit.
        $repository = Mockery::mock(MailAnalyticsRepository::class);
        $repository->shouldReceive('summary')->andReturn(
            new WP_Error('bit_smtp_analytics_database_error', 'The retained-log aggregate query failed.')
        );
        $repository->shouldReceive('timeSeries')->andReturn([]);
        $repository->shouldReceive('groups')->andReturn([]);
        $repository->shouldReceive('retainedRecordBounds')->andReturn(['earliest' => null, 'latest' => null]);

        $controller = new AnalyticsController($this->service($repository));

        $controller->overview(new Request());

        $this->assertSame(Response::ERROR, Response::getStatus());
        $this->assertSame('bit_smtp_analytics_database_error', Response::getCode());
        $this->assertSame(500, Response::getHttpStatusCode());
    }

    /**
     * Stub get_option so PluginSettings::getWithLegacyFallback('logging_enabled', ...) resolves to
     * the given value, matching how MailAnalyticsService::loggingEnabled() reads it.
     */
    private function stubLoggingEnabled(bool $enabled): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use ($enabled) {
            return $key === 'bit_smtp_logging_enabled' ? $enabled : $default;
        });
    }

    private function service(MailAnalyticsRepository $repository): MailAnalyticsService
    {
        return new MailAnalyticsService($repository, new Repository(new ArrayStore()));
    }

    /**
     * A repository double returning a small, deterministic fixture for every aggregate query.
     */
    private function repository(): Mockery\MockInterface
    {
        $repository = Mockery::mock(MailAnalyticsRepository::class);
        $repository->shouldReceive('summary')->andReturn([
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
        ]);
        $repository->shouldReceive('timeSeries')->andReturn([]);
        $repository->shouldReceive('groups')->andReturn([]);
        $repository->shouldReceive('retainedRecordBounds')->andReturn(['earliest' => null, 'latest' => null]);

        return $repository;
    }
}
