<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\LogController;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;

/**
 * Drives LogController::export against the real log table: the CSV honors the same filter whitelist as
 * the list view, exposes only safe metadata columns (never body/debug), defuses formula-injection cells,
 * and reports a bounded, non-silently-truncated result.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogExportTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testExportReturnsOnlyRowsMatchingTheStatusFilterWithSafeHeaderColumns(): void
    {
        $this->seedSuccess(['to_addr' => 'ok@example.test', 'subject' => 'Delivered']);
        $this->seedFailure(['to_addr' => 'bad@example.test', 'subject' => 'Bounced']);

        $data = $this->export(['status' => 'failed']);

        self::assertSame(1, $data['count']);
        self::assertFalse($data['truncated']);
        self::assertSame('bit-smtp-logs.csv', $data['filename']);

        $rows   = $this->parseCsv($data['csv']);
        $header = array_shift($rows);

        self::assertSame(LogService::EXPORT_SAFE_COLUMNS, $header);
        foreach (['details', 'debug_info', 'body', 'message', 'tracking_id'] as $forbidden) {
            self::assertNotContains($forbidden, $header, "the export header must never expose {$forbidden}");
        }

        self::assertCount(1, $rows);
        $statusIndex = array_search('status', $header, true);
        self::assertSame('failed', $rows[0][$statusIndex]);
    }

    public function testExportWithNoFilterIncludesEverySeededRow(): void
    {
        $this->seedSuccess(['to_addr' => 'a@example.test', 'subject' => 'A']);
        $this->seedSuccess(['to_addr' => 'b@example.test', 'subject' => 'B']);
        $this->seedFailure(['to_addr' => 'c@example.test', 'subject' => 'C']);

        $data = $this->export([]);

        self::assertSame(3, $data['count']);
        self::assertFalse($data['truncated']);
        self::assertCount(3, $this->dataRows($data['csv']));
    }

    public function testFormulaInjectionCellIsPrefixedWithASingleQuote(): void
    {
        $this->seedSuccess(['to_addr' => 'x@example.test', 'subject' => '=SUM(A1:A9)']);

        $data      = $this->export([]);
        $rows      = $this->dataRows($data['csv']);
        $subjectAt = array_search('subject', LogService::EXPORT_SAFE_COLUMNS, true);

        self::assertSame("'=SUM(A1:A9)", $rows[0][$subjectAt], 'a formula-triggering subject must be defused');
    }

    public function testExportNeverReturnsMoreRowsThanTheCap(): void
    {
        $this->seedSuccess(['to_addr' => 'a@example.test', 'subject' => 'A']);
        $this->seedSuccess(['to_addr' => 'b@example.test', 'subject' => 'B']);
        $this->seedSuccess(['to_addr' => 'c@example.test', 'subject' => 'C']);

        $data = $this->export([]);
        self::assertLessThanOrEqual(LogService::MAX_EXPORT_ROWS, $data['count']);

        // The service honors a smaller requested limit exactly.
        self::assertCount(2, $this->service->exportRows([], 2));
    }

    public function testExportOverTheCapReportsTruncatedAndClampsToTheCap(): void
    {
        // One past the cap: the controller's +1 probe must detect this and flag truncation (never silent).
        $this->seedManyRows(LogService::MAX_EXPORT_ROWS + 1);

        $data = $this->export([]);

        self::assertTrue($data['truncated'], 'exceeding the cap must be flagged, not silently dropped');
        self::assertSame(LogService::MAX_EXPORT_ROWS, $data['count']);
        self::assertCount(LogService::MAX_EXPORT_ROWS, $this->dataRows($data['csv']));
    }

    /**
     * Invoke the controller with the given filters set as request attributes; return the success data.
     *
     * @param array<string,string> $filters
     *
     * @return array<string,mixed>
     */
    private function export(array $filters): array
    {
        $request = new Request();
        foreach ($filters as $key => $value) {
            $request->{$key} = $value;
        }

        (new LogController())->export($request);
        self::assertSame(Response::SUCCESS, Response::getStatus());

        return (array) Response::getData();
    }

    /**
     * @return array<int,array<int,string>> every CSV row (header included), RFC-4180 parsed
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n/', rtrim($csv, "\r\n"));

        return array_map(static function (string $line): array {
            return str_getcsv($line, ',', '"', '');
        }, $lines);
    }

    /**
     * @return array<int,array<int,string>> CSV data rows only (header stripped)
     */
    private function dataRows(string $csv): array
    {
        $rows = $this->parseCsv($csv);
        array_shift($rows);

        return $rows;
    }

    /**
     * @param array<string,string> $overrides
     */
    private function seedSuccess(array $overrides): void
    {
        $this->insertLog(Log::SUCCESS, $overrides);
    }

    /**
     * @param array<string,string> $overrides
     */
    private function seedFailure(array $overrides): void
    {
        $this->insertLog(Log::ERROR, $overrides);
    }

    /**
     * Bulk-insert $count minimal log rows in one query (fast enough to seed past MAX_EXPORT_ROWS).
     */
    private function seedManyRows(int $count): void
    {
        global $wpdb;

        $table  = (new Log())->getTable();
        $tuple  = "(1, 'bulk', '[\"to@example.test\"]', '2026-02-01 10:00:00', '2026-02-01 10:00:00', '2026-02-01 10:00:00')";
        $values = implode(',', array_fill(0, $count, $tuple));

        $wpdb->query(
            "INSERT INTO `{$table}` (status, subject, to_addr, created_at, created_at_utc, updated_at) VALUES {$values}"
        );

        self::assertSame($count, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"));
    }

    /**
     * @param array<string,string> $overrides
     */
    private function insertLog(int $status, array $overrides): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'         => $status,
                'subject'        => $overrides['subject'] ?? 'Export subject',
                'to_addr'        => wp_json_encode([$overrides['to_addr'] ?? 'to@example.test']),
                'connection'     => $overrides['connection'] ?? 'Primary SMTP',
                'sender'         => $overrides['sender']     ?? 'from@example.test',
                'created_at'     => '2026-02-01 10:00:00',
                'created_at_utc' => '2026-02-01 10:00:00',
                'updated_at'     => '2026-02-01 10:00:00',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        self::assertSame(1, $inserted);
    }
}
