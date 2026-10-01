<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\TelemetryController;
use BitApps\SMTP\HTTP\Requests\TelemetryConsentRequest;
use BitApps\SMTP\HTTP\Services\TelemetryConsentService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class TelemetryControllerTest extends BaseUnitTestCase
{
    protected function tearDown(): void
    {
        Response::reset();
        parent::tearDown();
    }

    public function testStatusReturnsTheServiceStatus(): void
    {
        $service = Mockery::mock(TelemetryConsentService::class);
        $service->shouldReceive('status')->once()->andReturn(['answered' => false, 'allowed' => false]);

        (new TelemetryController($service))->status();

        self::assertSame(Response::SUCCESS, Response::getStatus());
        self::assertSame(['answered' => false, 'allowed' => false], (array) Response::getData());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function allowValues(): array
    {
        return ['bool' => [true], 'string' => ['true'], 'int' => [1]];
    }

    #[DataProvider('allowValues')]
    public function testAllowAnswerOptsIn(mixed $allow): void
    {
        $service = Mockery::mock(TelemetryConsentService::class);
        $service->shouldReceive('allow')->once()->andReturn(['answered' => true, 'allowed' => true]);
        $service->shouldNotReceive('decline');

        (new TelemetryController($service))->saveConsent($this->requestWith($allow));

        self::assertSame(['answered' => true, 'allowed' => true], (array) Response::getData());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function declineValues(): array
    {
        return ['bool' => [false], 'string' => ['false'], 'zero' => ['0']];
    }

    #[DataProvider('declineValues')]
    public function testDeclineAnswerNeverOptsIn(mixed $allow): void
    {
        $service = Mockery::mock(TelemetryConsentService::class);
        $service->shouldReceive('decline')->once()->andReturn(['answered' => true, 'allowed' => false]);
        $service->shouldNotReceive('allow');

        (new TelemetryController($service))->saveConsent($this->requestWith($allow));

        self::assertSame(['answered' => true, 'allowed' => false], (array) Response::getData());
    }

    /**
     * Build a consent request double whose validated body carries the given answer.
     */
    private function requestWith(mixed $allow): TelemetryConsentRequest
    {
        $request = Mockery::mock(TelemetryConsentRequest::class);
        $request->shouldReceive('validated')->andReturn(['allow' => $allow]);

        return $request;
    }
}
