<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\TestMailReport;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Covers the test-mail outcome report: which connection sent it and whether failover was needed.
 *
 * @internal
 *
 * @coversNothing
 */
final class TestMailReportTest extends BaseUnitTestCase
{
    public function testFirstAttemptSuccessReportsTheSenderWithoutFailover(): void
    {
        $report = TestMailReport::fromAttempts(true, [$this->attempt('Primary', 'sent')], ['220 OK'])->toArray();

        $this->assertSame('Primary', $report['sent_via']);
        $this->assertFalse($report['failed_over']);
        $this->assertSame(['220 OK'], $report['debug']);
        $this->assertCount(1, $report['attempts']);
    }

    public function testBackupSuccessIsReportedAsFailover(): void
    {
        $report = TestMailReport::fromAttempts(true, [
            $this->attempt('Primary', 'failed', 'Auth rejected'),
            $this->attempt('Backup', 'sent'),
        ], [])->toArray();

        $this->assertSame('Backup', $report['sent_via']);
        $this->assertTrue($report['failed_over']);
        $this->assertSame('Auth rejected', $report['attempts'][0]['error']);
    }

    public function testFailureHasNoSenderAndNoFailover(): void
    {
        $report = TestMailReport::fromAttempts(false, [
            $this->attempt('Primary', 'failed', 'Timeout'),
            $this->attempt('Backup', 'failed', 'Refused'),
        ], ['boom'])->toArray();

        $this->assertNull($report['sent_via']);
        $this->assertFalse($report['failed_over']);
    }

    public function testNativeSendWithoutAttemptsHasNoSender(): void
    {
        $report = TestMailReport::fromAttempts(true, [], [])->toArray();

        $this->assertNull($report['sent_via']);
        $this->assertFalse($report['failed_over']);
        $this->assertSame([], $report['attempts']);
    }

    public function testDebugLinesAreTrimmedAndBlankLinesDropped(): void
    {
        $report = TestMailReport::fromAttempts(false, [], ["line one\n", '  ', "line two\n"])->toArray();

        $this->assertSame(['line one', 'line two'], $report['debug']);
    }

    /**
     * @return array{connection: string, status: string, error: ?string}
     */
    private function attempt(string $label, string $status, ?string $error = null): array
    {
        return ['connection' => $label, 'status' => $status, 'error' => $error];
    }
}
