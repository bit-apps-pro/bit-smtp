<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Health;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Health\ProbeResult;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use ReflectionMethod;

/**
 * Covers the probe's error-to-ProbeResult mapping in isolation (no socket). The live connect/EHLO/
 * AUTH path and its always-close guarantee need the WP-bundled PHPMailer, so they live in
 * tests/Integration/HealthCheckCronTest.
 *
 * @internal
 *
 * @coversNothing
 */
final class SmtpConnectionProbeTest extends BaseUnitTestCase
{
    public function testAnAuthErrorClassifiesAsAnAuthFailure(): void
    {
        $result = $this->toFailure('SMTP Error: Could not authenticate. Server response: 535 5.7.8', '0');

        self::assertFalse($result->isOk());
        self::assertSame(FailureCategory::AUTH, $result->getFailureClass());
        self::assertNotNull($result->getError());
    }

    public function testAConnectErrorClassifiesAsTransient(): void
    {
        $result = $this->toFailure('SMTP Error: Could not connect to SMTP host.', '0');

        self::assertSame(FailureCategory::TRANSIENT, $result->getFailureClass());
    }

    public function testTheErrorIsFlattenedToASingleSafeLine(): void
    {
        $result = $this->toFailure("SMTP connect() failed.\r\n   https://example.test/wiki", '2');

        $error = (string) $result->getError();
        self::assertStringNotContainsString("\n", $error);
        self::assertStringNotContainsString('  ', $error);
        self::assertSame('SMTP connect() failed. https://example.test/wiki', $error);
    }

    private function toFailure(string $message, string $code): ProbeResult
    {
        $probe  = new SmtpConnectionProbe(Mockery::mock(SmtpTransport::class), new FailureClassifier());
        $method = new ReflectionMethod(SmtpConnectionProbe::class, 'toFailure');
        $method->setAccessible(true);

        return $method->invoke($probe, $message, $code);
    }
}
