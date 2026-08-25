<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Tracking\EngagementRecorder;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Verifies the recorder's contract against a mocked LogService: a verified token folds a fire onto
 * its log, a click carries its signed URL as the target, an automated fire sets the flag, and a
 * bad/tampered token or an unknown log writes nothing. The log is resolved with a SINGLE
 * findByTrackingId() fetch — both its id and its send time come from that one model.
 *
 * @internal
 *
 * @coversNothing
 */
final class EngagementRecorderTest extends BaseUnitTestCase
{
    private const TRACKING_ID = 'a1b2c3d4-e5f6-7890-abcd-ef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
        // Log's magic accessors reach the query builder, which reads the wpdb prefix.
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    public function testRecordsAHumanOpenAgainstTheResolvedLog(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('findByTrackingId')->once()->with(self::TRACKING_ID)->andReturn($this->logSentSecondsAgo(3600, 42));
        $logs->shouldReceive('recordEngagement')->once()->with(42, 'open', '', false);

        (new EngagementRecorder($logs))->record(
            TokenSigner::sign(['t' => self::TRACKING_ID]),
            EngagementRecorder::TYPE_OPEN,
            $this->humanRequest()
        );
    }

    public function testClickRecordsTheSignedUrlAsTheTarget(): void
    {
        $url  = 'https://example.test/order/42?ref=1';
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('findByTrackingId')->andReturn($this->logSentSecondsAgo(3600, 7));
        $logs->shouldReceive('recordEngagement')->once()->with(7, 'click', $url, false);

        (new EngagementRecorder($logs))->record(
            TokenSigner::sign(['t' => self::TRACKING_ID, 'u' => $url]),
            EngagementRecorder::TYPE_CLICK,
            $this->humanRequest()
        );
    }

    public function testPrefetchFireSetsTheAutomatedFlag(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('findByTrackingId')->andReturn($this->logSentSecondsAgo(2, 9));
        $logs->shouldReceive('recordEngagement')->once()->with(9, 'open', '', true);

        (new EngagementRecorder($logs))->record(
            TokenSigner::sign(['t' => self::TRACKING_ID]),
            EngagementRecorder::TYPE_OPEN,
            $this->humanRequest()
        );
    }

    public function testTamperedTokenIsANoOp(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldNotReceive('findByTrackingId');
        $logs->shouldNotReceive('recordEngagement');

        [$payload, $signature] = explode('.', TokenSigner::sign(['t' => self::TRACKING_ID]));
        $signature[0]          = $signature[0] === 'a' ? 'b' : 'a';

        (new EngagementRecorder($logs))->record($payload . '.' . $signature, EngagementRecorder::TYPE_OPEN, $this->humanRequest());
    }

    public function testUnknownLogIsANoOp(): void
    {
        $logs = Mockery::mock(LogService::class);
        $logs->shouldReceive('findByTrackingId')->andReturn(null);
        $logs->shouldNotReceive('recordEngagement');

        (new EngagementRecorder($logs))->record(
            TokenSigner::sign(['t' => self::TRACKING_ID]),
            EngagementRecorder::TYPE_OPEN,
            $this->humanRequest()
        );
    }

    private function logSentSecondsAgo(int $seconds, int $id): Log
    {
        $log                 = new Log();
        $log->id             = $id;
        $log->created_at_utc = gmdate('Y-m-d H:i:s', time() - $seconds);

        return $log;
    }

    /**
     * @return array{ip: string, user_agent: string}
     */
    private function humanRequest(): array
    {
        return ['ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/121.0'];
    }
}
