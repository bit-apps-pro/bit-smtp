<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Privacy\EngagementDataEraser;
use BitApps\SMTP\Privacy\EngagementDataExporter;
use BitApps\SMTP\Privacy\RecipientLogLocator;
use BitSmtpEngagementTableMigration;

// Global-namespace migration class, included directly (migrations are not PSR-4 autoloaded).
require_once \dirname(__DIR__, 2) . '/backend/db/Migrations/BitSmtpEngagementTableMigration.php';

/**
 * Exercises the Phase 8.4 GDPR surface against the real WordPress test DB: the engagement exporter
 * and eraser scope strictly to the requested recipient email (matched through the to_addr JSON
 * array), never leak or delete another recipient's data, paginate per WP's privacy contract, and
 * fail closed when a delete errors.
 *
 * @internal
 *
 * @coversNothing
 */
final class EngagementPrivacyTest extends IntegrationTestCase
{
    private LogService $service;

    private string $engagementTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engagementTable = (new LogEngagementEvent())->getTable();
        (new BitSmtpEngagementTableMigration())->up();
        $this->truncateTables($this->engagementTable, (new Log())->getTable());
        $this->service = new LogService();
    }

    public function testExporterReturnsOnlyTheRequestedRecipientsEvents(): void
    {
        $logA1 = $this->seedLog(['alice@example.com']);
        $logA2 = $this->seedLog(['Alice Example <alice@example.com>']); // display-name recipient form
        $logB1 = $this->seedLog(['bob@example.com']);

        $this->service->recordEngagement($logA1, 'open', '', false);
        $this->service->recordEngagement($logA1, 'click', 'https://example.test/a', false);
        $this->service->recordEngagement($logA2, 'open', '', false);
        $this->service->recordEngagement($logB1, 'open', '', false);
        $this->service->recordEngagement($logB1, 'click', 'https://example.test/b', false);

        $result = (new EngagementDataExporter())->export('alice@example.com', 1);

        self::assertTrue($result['done']);
        self::assertCount(3, $result['data'], 'exactly the requested recipient\'s three engagement rows');

        $logIds = $this->logIdsFromItems($result['data']);
        self::assertSame([$logA1, $logA2], $this->uniqueSorted($logIds));
        self::assertNotContains($logB1, $logIds, 'another recipient\'s log must never appear in the export');
    }

    public function testEraserDeletesOnlyTheRequestedRecipientsEngagementEvents(): void
    {
        $logA1 = $this->seedLog(['alice@example.com']);
        $logA2 = $this->seedLog(['Alice Example <alice@example.com>']);
        $logB1 = $this->seedLog(['bob@example.com']);

        $this->service->recordEngagement($logA1, 'open', '', false);
        $this->service->recordEngagement($logA1, 'click', 'https://example.test/a', false);
        $this->service->recordEngagement($logA2, 'open', '', false);
        $this->service->recordEngagement($logB1, 'open', '', false);
        $this->service->recordEngagement($logB1, 'click', 'https://example.test/b', false);

        $result = (new EngagementDataEraser())->erase('alice@example.com', 1);

        self::assertTrue($result['items_removed']);
        self::assertFalse($result['items_retained']);
        self::assertSame([], $result['messages']);
        self::assertTrue($result['done']);

        self::assertSame(0, LogEngagementEvent::where('log_id', $logA1)->count());
        self::assertSame(0, LogEngagementEvent::where('log_id', $logA2)->count());
        self::assertSame(2, LogEngagementEvent::where('log_id', $logB1)->count(), 'another recipient\'s events must be untouched');

        // The eraser is scoped to engagement events only: the log rows themselves must survive.
        self::assertInstanceOf(Log::class, Log::where('id', $logA1)->first());
    }

    public function testNonMatchingEmailExportsAndErasesNothing(): void
    {
        $logA1 = $this->seedLog(['alice@example.com']);
        $this->service->recordEngagement($logA1, 'open', '', false);

        $export = (new EngagementDataExporter())->export('carol@example.com', 1);
        self::assertSame([], $export['data']);
        self::assertTrue($export['done']);

        $erase = (new EngagementDataEraser())->erase('carol@example.com', 1);
        self::assertFalse($erase['items_removed']);
        self::assertFalse($erase['items_retained']);
        self::assertTrue($erase['done']);

        self::assertSame(1, LogEngagementEvent::where('log_id', $logA1)->count(), 'a request for another subject must not touch Alice');
    }

    public function testSubstringOfAnotherRecipientDoesNotMatch(): void
    {
        $logA1 = $this->seedLog(['alice@example.com']);
        $this->service->recordEngagement($logA1, 'open', '', false);

        // 'lice@example.com' is a LIKE substring of 'alice@example.com' but a different address.
        $export = (new EngagementDataExporter())->export('lice@example.com', 1);
        self::assertSame([], $export['data'], 'a substring of a real recipient must not leak their data');
    }

    public function testPaginationDoneFlipsAcrossPages(): void
    {
        $recipient = 'dave@example.com';
        for ($i = 0; $i <= RecipientLogLocator::PAGE_SIZE; $i++) {
            $this->seedLog([$recipient]);
        }

        $page1 = (new EngagementDataExporter())->export($recipient, 1);
        self::assertFalse($page1['done'], 'a full first page must report that more pages remain');

        $page2 = (new EngagementDataExporter())->export($recipient, 2);
        self::assertTrue($page2['done'], 'the trailing partial page must report done');
    }

    public function testEraserFailsClosedWhenDeletionErrors(): void
    {
        $logA1 = $this->seedLog(['alice@example.com']);
        $this->service->recordEngagement($logA1, 'open', '', false);

        global $wpdb;
        // Force a delete error by removing the table out from under the eraser; the locator still
        // resolves candidate logs from the intact logs table, so the delete is the failure point.
        $this->dropTables($this->engagementTable);

        try {
            $wpdb->suppress_errors(true);
            $result = (new EngagementDataEraser())->erase('alice@example.com', 1);
        } finally {
            $wpdb->suppress_errors(false);
            (new BitSmtpEngagementTableMigration())->up();
        }

        self::assertFalse($result['items_removed'], 'a failed delete must never claim removal');
        self::assertTrue($result['items_retained'], 'a failed delete must report retention (fail closed)');
        self::assertNotEmpty($result['messages'], 'the subject must be told data was retained');
    }

    /**
     * Persist a bare success log with the given recipients and return its id.
     *
     * @param array<int,string> $recipients
     */
    private function seedLog(array $recipients): int
    {
        $log          = new Log();
        $log->status  = Log::SUCCESS;
        $log->subject = 'Subject';
        $log->to_addr = $recipients;
        $log->save();

        return (int) $log->id;
    }

    /**
     * Pull the "Log entry ID" values out of a set of exporter items.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,int>
     */
    private function logIdsFromItems(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ($item['data'] as $field) {
                if ($field['name'] === 'Log entry ID') {
                    $ids[] = (int) $field['value'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<int,int> $ids
     *
     * @return array<int,int>
     */
    private function uniqueSorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
