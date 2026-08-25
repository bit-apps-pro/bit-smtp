<?php

namespace BitApps\SMTP\Tests\Integration\Tracking;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Support\ModelRows;
use BitApps\SMTP\Mail\Tracking\ClickTracker;
use BitApps\SMTP\Mail\Tracking\OpenTracker;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;

/**
 * Drives the Open/Click trackers against the real test DB (mirrors WebhookControllerTest): a valid
 * open/click records and folds an engagement row, a click yields its original destination, an
 * invalid/tampered token writes nothing while still yielding a benign result, a prefetch-window fire
 * is flagged automated, and deleting a log cascades its engagement rows away.
 *
 * @internal
 *
 * @coversNothing
 */
final class TrackingEndpointTest extends IntegrationTestCase
{
    private const TRACKING_ID = 'a1b2c3d4-e5f6-7890-abcd-ef0123456789';

    private LogService $service;

    private string $engagementTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engagementTable = (new LogEngagementEvent())->getTable();
        $this->truncate($this->engagementTable);
        $this->truncate((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testValidOpenTokenRecordsAHumanRow(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID);

        (new OpenTracker())->handle($this->openToken(self::TRACKING_ID), $this->humanRequest());

        $rows = $this->rows($logId);
        self::assertCount(1, $rows);
        self::assertSame('open', $rows[0]->type);
        self::assertSame('', $rows[0]->target);
        self::assertSame(1, (int) $rows[0]->hits);
        self::assertSame(0, (int) $rows[0]->automated_hits);
    }

    public function testRefiredOpenFoldsOntoASingleRow(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID);
        $token = $this->openToken(self::TRACKING_ID);

        (new OpenTracker())->handle($token, $this->humanRequest());
        (new OpenTracker())->handle($token, $this->humanRequest());

        $rows = $this->rows($logId);
        self::assertCount(1, $rows, 'a re-fired open must fold onto one row');
        self::assertSame(2, (int) $rows[0]->hits);
    }

    public function testValidClickRecordsAndYieldsTheOriginalUrl(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID);
        $url   = 'https://example.test/order/42?ref=1';

        $destination = (new ClickTracker())->handle($this->clickToken(self::TRACKING_ID, $url), $this->humanRequest());

        self::assertSame($url, $destination, 'a verified click must redirect to the signed destination');
        $rows = $this->rows($logId);
        self::assertCount(1, $rows);
        self::assertSame('click', $rows[0]->type);
        self::assertSame($url, $rows[0]->target);
    }

    public function testTamperedTokenRecordsNothingAndYieldsNoRedirect(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID);

        [$payload, $signature] = explode('.', $this->clickToken(self::TRACKING_ID, 'https://example.test/x'));
        $signature[0]          = $signature[0] === 'a' ? 'b' : 'a';
        $tampered              = $payload . '.' . $signature;

        (new OpenTracker())->handle($tampered, $this->humanRequest());
        $destination = (new ClickTracker())->handle($tampered, $this->humanRequest());

        self::assertNull($destination, 'a tampered token must never redirect off-site');
        self::assertCount(0, $this->rows($logId), 'a tampered token must write no row');
    }

    public function testValidlySignedTokenForAnUnknownLogRecordsNothing(): void
    {
        // A correctly signed token whose tracking uuid matches no persisted log (logging off, purged,
        // or stale) must be a silent no-op.
        (new OpenTracker())->handle($this->openToken('no-such-tracking-uuid'), $this->humanRequest());

        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$this->engagementTable}`"));
    }

    public function testNonHttpClickDestinationIsNotRedirected(): void
    {
        // Defense-in-depth: even a validly signed non-http(s) 'u' must not produce a redirect target.
        self::assertNull(ClickTracker::destination($this->clickToken(self::TRACKING_ID, 'mailto:evil@example.test')));
        self::assertNull(ClickTracker::destination($this->clickToken(self::TRACKING_ID, 'javascript:alert(1)')));
    }

    public function testPrefetchWindowFireIsFlaggedAutomated(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID, 1);

        (new OpenTracker())->handle($this->openToken(self::TRACKING_ID), $this->humanRequest());

        $row = $this->rows($logId)[0];
        self::assertSame(1, (int) $row->hits);
        self::assertSame(1, (int) $row->automated_hits, 'a fire within the prefetch window is flagged automated');
    }

    public function testLogDeleteCascadesEngagementRows(): void
    {
        $logId = $this->seedLog(self::TRACKING_ID);
        (new OpenTracker())->handle($this->openToken(self::TRACKING_ID), $this->humanRequest());
        self::assertCount(1, $this->rows($logId));

        self::assertTrue($this->service->delete([$logId]));

        self::assertSame(0, LogEngagementEvent::where('log_id', $logId)->count(), 'deleting a log must cascade its engagement rows');
        self::assertEmpty(Log::where('id', $logId)->first());
    }

    private function openToken(string $trackingId): string
    {
        return TokenSigner::sign(['t' => $trackingId]);
    }

    private function clickToken(string $trackingId, string $url): string
    {
        return TokenSigner::sign(['t' => $trackingId, 'u' => $url]);
    }

    /**
     * Persist a log carrying a tracking token, backdated so a normal open is not misread as a
     * prefetch. A small $ageSeconds reproduces the prefetch window.
     */
    private function seedLog(string $trackingId, int $ageSeconds = 3600): int
    {
        $log                 = new Log();
        $log->status         = Log::SUCCESS;
        $log->subject        = 'Subject';
        $log->to_addr        = ['recipient@example.com'];
        $log->tracking_id    = $trackingId;
        $log->created_at_utc = gmdate('Y-m-d H:i:s', time() - $ageSeconds);
        $log->save();

        return (int) $log->id;
    }

    /**
     * @return array{ip: string, user_agent: string}
     */
    private function humanRequest(): array
    {
        return ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/121.0'];
    }

    /**
     * @return array<int,LogEngagementEvent>
     */
    private function rows(int $logId): array
    {
        return ModelRows::toArray(LogEngagementEvent::where('log_id', $logId)->orderBy('id')->get());
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$table}`");
    }
}
