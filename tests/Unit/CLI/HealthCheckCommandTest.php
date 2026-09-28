<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\HealthCheckCommand;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers health-check command logic: runs probe pass, renders per-connection health as table.
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

            'ghost' => ConnectionHealth::unknown()->with([
                'status' => HealthStatus::HEALTHY,
            ]),
        ]);

        $settings = MailSettings::fromArray([
            'connections' => [
                ['id' => 'conn-1', 'provider' => 'ses', 'kind' => 'api', 'name' => 'Primary SES'],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'smtp', 'name' => ''],
            ],
        ]);
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->once()->andReturn($settings);

        Functions\when('__')->returnArg(1);

        $reporter = new FakeCliReporter();
        (new HealthCheckCommand($runner, $health, $config))->run([], [], $reporter);

        $this->assertCount(1, $reporter->rendered);
        $this->assertSame('table', $reporter->rendered[0]['format']);

        $items = $reporter->rendered[0]['items'];
        $this->assertCount(3, $items);
        $this->assertSame('Primary SES', $items[0]['connection']);
        $this->assertSame(HealthStatus::HEALTHY, $items[0]['status']);
        $this->assertSame('smtp', $items[1]['connection']);
        $this->assertSame(HealthStatus::UNHEALTHY, $items[1]['status']);
        $this->assertSame('connect timed out', $items[1]['last_error']);
        $this->assertSame('Deleted connection', $items[2]['connection']);
    }

    public function testReportsNoConnectionsWhenTheHealthMapIsEmpty(): void
    {
        $runner = Mockery::mock(HealthProbeRunner::class);
        $runner->shouldReceive('run')->once();

        $health = Mockery::mock(ConnectionHealthService::class);
        $health->shouldReceive('list')->once()->andReturn([]);

        $config = Mockery::mock(MailConfigService::class);

        $reporter = new FakeCliReporter();
        (new HealthCheckCommand($runner, $health, $config))->run([], [], $reporter);

        $this->assertSame(['No connections configured.'], $reporter->lines);
        $this->assertSame([], $reporter->rendered);
    }
}
