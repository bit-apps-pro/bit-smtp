<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Health\HealthProbeResolver;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthProbeResolverTest extends BaseUnitTestCase
{
    public function testSmtpConnectionsResolveToTheSmtpProbe(): void
    {
        [$resolver, $smtp] = $this->resolver();

        self::assertSame($smtp, $resolver->probeFor($this->connection('smtp')));
    }

    public function testLocalConnectionsHaveNoActiveProbe(): void
    {
        [$resolver] = $this->resolver();

        self::assertNull($resolver->probeFor($this->connection('local')));
    }

    public function testApiConnectionsHaveNoActiveProbe(): void
    {
        [$resolver] = $this->resolver();

        self::assertNull($resolver->probeFor($this->connection('api')));
    }

    /**
     * @return array{0: HealthProbeResolver, 1: SmtpConnectionProbe}
     */
    private function resolver(): array
    {
        $smtp = new SmtpConnectionProbe(Mockery::mock(SmtpTransport::class), new FailureClassifier());

        return [new HealthProbeResolver($smtp), $smtp];
    }

    private function connection(string $kind): Connection
    {
        return Connection::fromArray(['id' => 'conn', 'provider' => 'provider', 'kind' => $kind]);
    }
}
