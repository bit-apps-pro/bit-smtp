<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\HealthCheckCommand;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Mockery;

/**
 * Covers the health-check command logic: it runs the probe pass, then renders the refreshed
 * per-connection public health map (keyed by connection id) as a table.
 *
 * @internal
 *
 * @coversNothing
 */
final class HealthCheckCommandTest extends BaseUnitTestCase
{
    public function testRunsTheProbeThenRendersThePublicHealthPerConnection(): void
    {
        $runner = Mockery::mock(HealthProbeRunner::class);
        $runner->shouldReceive('run')->once();

        $health = Mockery::mock(ConnectionHealthService::class);
        $health->shouldReceive('list')->once()->andReturn([
            'conn-1' => ConnectionHealth::unknown()->with([
                'status'               => HealthStatus::HEALTHY,
                'consecutive_failures' => 0,
                'last_ok_at'           => '2026-01-01 00:00:00',
            ]),
            'conn-2' => ConnectionHealth::unknown()->with([
                'status'               => HealthStatus::UNHEALTHY,
                'consecutive_failures' => 4,
                'last_error'           => 'connect timed out',
            ]),
        ]);

        $reporter = new FakeCliReporter();
        (new HealthCheckCommand($runner, $health))->run([], [], $reporter);

        $this->assertCount(1, $reporter->rendered);
        $this->assertSame('table', $reporter->rendered[0]['format']);

        $items = $reporter->rendered[0]['items'];
        $this->assertCount(2, $items);
        $this->assertSame('conn-1', $items[0]['connection_id']);
        $this->assertSame(HealthStatus::HEALTHY, $items[0]['status']);
        $this->assertSame('closed', $items[0]['circuit']);
        $this->assertSame('conn-2', $items[1]['connection_id']);
        $this->assertSame(HealthStatus::UNHEALTHY, $items[1]['status']);
        $this->assertSame('open', $items[1]['circuit']);
        $this->assertSame('connect timed out', $items[1]['last_error']);
    }

    public function testReportsNoConnectionsWhenTheHealthMapIsEmpty(): void
    {
        $runner = Mockery::mock(HealthProbeRunner::class);
        $runner->shouldReceive('run')->once();

        $health = Mockery::mock(ConnectionHealthService::class);
        $health->shouldReceive('list')->once()->andReturn([]);

        $reporter = new FakeCliReporter();
        (new HealthCheckCommand($runner, $health))->run([], [], $reporter);

        $this->assertSame(['No connections configured.'], $reporter->lines);
        $this->assertSame([], $reporter->rendered);
    }
}
