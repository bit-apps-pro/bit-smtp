<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeWpdb;
use BitApps\SMTP\Tests\InteractsWithFakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Docker-free complement to tests/Integration/LogsFilterTest.php: asserts on the SQL that
 * LogService::all()'s filter whitelist GENERATES, via a fake $wpdb that captures every executed
 * query string instead of running against a real database. The integration suite already proves
 * the filters narrow real rows; this suite proves the query builder emits the SQL shape (and only
 * binds values through prepare(), never concatenates them) that makes that narrowing possible.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogsFilterSqlTest extends BaseUnitTestCase
{
    use InteractsWithFakeWpdb;

    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = $this->installFakeWpdb();
    }

    protected function tearDown(): void
    {
        $this->resetFakeWpdb();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,string>   $mustContain
     * @param array<int,string>   $mustNotContain
     */
    #[DataProvider('filterProvider')]
    public function testFilterProducesExpectedSqlFragment(array $filters, array $mustContain, array $mustNotContain): void
    {
        $this->logService()->all(0, 20, $filters);

        $pagedSql = $this->wpdb->queries[0];

        foreach ($mustContain as $fragment) {
            self::assertStringContainsString($fragment, $pagedSql);
        }

        foreach ($mustNotContain as $fragment) {
            self::assertStringNotContainsString($fragment, $pagedSql);
        }
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:array<int,string>,2:array<int,string>}>
     */
    public static function filterProvider(): array
    {
        return [
            'to_addr becomes a LIKE clause wrapped in wildcards' => [
                ['to_addr' => 'test@example.com'],
                ['to_addr', 'LIKE', "'%test@example.com%'"],
                [],
            ],
            // where('column', value) (an implicit-equals 2-arg call, as LogService uses for
            // status/delivery_status/source_plugin) renders with a double space around "=": the
            // operator fragment contributes a trailing space and the value fragment its own
            // leading space. The 3-arg form used for date_from/date_to (explicit '>=' / '<=')
            // does not double up, so only these three fragments need the extra space.
            'status=failed binds the ERROR flag (0)' => [
                ['status' => 'failed'],
                ['`status` =  0'],
                [],
            ],
            'status=sent binds the SUCCESS flag (1)' => [
                ['status' => 'sent'],
                ['`status` =  1'],
                [],
            ],
            'delivery_status=bounced is whitelisted and bound as-is' => [
                ['delivery_status' => 'bounced'],
                ["`delivery_status` =  'bounced'"],
                [],
            ],
            'delivery_status outside the whitelist is dropped entirely' => [
                ['delivery_status' => 'nope'],
                [],
                ['delivery_status'],
            ],
            'connection_id filters by id-or-legacy-label via a grouped OR' => [
                ['connection_id' => 'conn-a'],
                ["( `wp_bit_smtp_logs`.`connection_id` =  'conn-a' OR `wp_bit_smtp_logs`.`connection` =  'conn-a')"],
                [],
            ],
            'source_plugin is an equality match' => [
                ['source_plugin' => 'woocommerce'],
                ["`source_plugin` =  'woocommerce'"],
                [],
            ],
            'date_from + date_to becomes a BETWEEN clause' => [
                ['date_from' => '2026-01-10', 'date_to' => '2026-01-20'],
                ["`created_at` BETWEEN '2026-01-10 00:00:00' AND '2026-01-20 23:59:59'"],
                [],
            ],
            'date_from alone is a one-sided >= clause' => [
                ['date_from' => '2026-01-10'],
                ["`created_at` >= '2026-01-10 00:00:00'"],
                ['BETWEEN'],
            ],
            'date_to alone is a one-sided <= clause' => [
                ['date_to' => '2026-01-20'],
                ["`created_at` <= '2026-01-20 23:59:59'"],
                ['BETWEEN'],
            ],
            'a malformed date_from is rejected and adds no date clause' => [
                ['date_from' => 'nope'],
                [],
                ['created_at'],
            ],
        ];
    }

    /**
     * The count() query backing pagination must reflect the same filters but never carry the
     * paged query's LIMIT/OFFSET — reusing the paged builder for count() would starve rows off any
     * page past the first (see LogService::all()'s inline comment).
     */
    public function testCountQueryOmitsLimitAndOffsetWhileThePagedQueryKeepsThem(): void
    {
        $this->logService()->all(0, 20, ['delivery_status' => 'bounced']);

        self::assertCount(2, $this->wpdb->queries);
        [$pagedSql, $countSql] = $this->wpdb->queries;

        self::assertStringContainsString('LIMIT', $pagedSql);
        self::assertStringContainsString('OFFSET', $pagedSql);

        self::assertStringContainsString('COUNT(', $countSql);
        self::assertStringNotContainsString('LIMIT', $countSql);
        self::assertStringNotContainsString('OFFSET', $countSql);
    }

    /**
     * A value shaped like a SQL-injection payload must only ever reach the query as a bound
     * prepare() value, never as text concatenated into the SQL template: the raw templates
     * captured pre-substitution must never contain the payload, while the final, substituted
     * query legitimately contains it — quoted, inside the single LIKE placeholder slot.
     */
    public function testToAddrFilterBindsAnInjectionPayloadRatherThanConcatenatingIt(): void
    {
        $payload = "x' OR '1'='1";

        $this->logService()->all(0, 20, ['to_addr' => $payload]);

        self::assertGreaterThan(0, $this->wpdb->prepareCalls);
        foreach ($this->wpdb->preparedTemplates as $template) {
            self::assertStringNotContainsString($payload, $template);
        }

        $pagedSql = $this->wpdb->queries[0];
        self::assertStringContainsString("LIKE '%x' OR '1'='1%'", $pagedSql);
    }

    /**
     * LogService::all() only touches the Log model and its own filter/date helpers — none of the
     * constructor's side effects (self::initializeLoggingContinuity(), which reads/writes WP
     * options via PluginSettings/Config) are reachable from it. Building the instance via
     * reflection, bypassing __construct(), keeps this suite from needing to stub those WP option
     * functions for a test that is only exercising SQL generation.
     */
    private function logService(): LogService
    {
        return (new ReflectionClass(LogService::class))->newInstanceWithoutConstructor();
    }
}
