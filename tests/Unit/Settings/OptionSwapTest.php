<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Settings\OptionSwap;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeWpdb;
use BitApps\SMTP\Tests\InteractsWithFakeWpdb;
use Brain\Monkey\Functions;

/**
 * Docker-free check of SQL OptionSwap: verifies conditional statement structure and gate logic.
 *
 * @internal
 *
 * @coversNothing
 */
final class OptionSwapTest extends BaseUnitTestCase
{
    use InteractsWithFakeWpdb;

    private FakeWpdb $wpdb;

    /**
     * @var list<string>
     */
    private array $invalidatedCacheKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = $this->installFakeWpdb();
        Functions\when('maybe_serialize')->alias(static fn ($value) => \is_array($value) ? serialize($value) : $value);
        Functions\when('wp_cache_delete')->alias(function (string $key, string $group): bool {
            $this->invalidatedCacheKeys[] = "{$group}:{$key}";

            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->resetFakeWpdb();
        parent::tearDown();
    }

    public function testCreateIsAnInsertIgnoreIntoTheCoreOptionsTable(): void
    {
        $this->wpdb->rows_affected = 1;

        $this->assertTrue(OptionSwap::create('probe', 'value'));
        $this->assertSame(
            "INSERT IGNORE INTO wp_options (`option_name`, `option_value`, `autoload`) VALUES ('probe', 'value', 'yes')",
            $this->lastQuery()
        );
    }

    public function testCreateLosesWhenTheRowAlreadyExists(): void
    {
        $this->wpdb->rows_affected = 0;

        $this->assertFalse(OptionSwap::create('probe', 'value'));
    }

    public function testReplaceUpdatesOnlyTheRowStillHoldingTheExpectedBytes(): void
    {
        $this->wpdb->rows_affected = 1;

        $this->assertTrue(OptionSwap::replace('probe', 'old', 'new'));
        $this->assertSame(
            "UPDATE wp_options SET `option_value` = 'new' WHERE `wp_options`.`option_name` = 'probe' AND option_value = BINARY 'old'",
            $this->lastQuery()
        );
    }

    public function testDeleteRemovesOnlyTheRowStillHoldingTheExpectedBytes(): void
    {
        $this->wpdb->rows_affected = 1;

        $this->assertTrue(OptionSwap::delete('probe', 'old'));
        $this->assertSame(
            "DELETE FROM wp_options WHERE `wp_options`.`option_name` = 'probe' AND option_value = BINARY 'old'",
            $this->lastQuery()
        );
    }

    public function testALostSwapIsReportedAndStillInvalidatesTheOptionCaches(): void
    {
        $this->wpdb->rows_affected = 0;

        $this->assertFalse(OptionSwap::replace('probe', 'stale', 'new'));
        $this->assertSame(['options:probe', 'options:alloptions', 'options:notoptions'], $this->invalidatedCacheKeys);
    }

    public function testExpectedValueIsBoundThroughPrepareNeverInterpolatedIntoTheTemplate(): void
    {
        OptionSwap::replace('probe', "x' OR '1'='1", 'new');

        $template = end($this->wpdb->preparedTemplates);
        $this->assertStringNotContainsString("OR '1'='1", $template);
        $this->assertStringContainsString('option_value = BINARY %s', $template);
    }

    public function testGateCreatesANonAutoloadedRowUnderThePrefixedName(): void
    {
        $this->wpdb->rows_affected = 1;

        $this->assertTrue((new FailureNotificationGate('failure_resolved_incidents'))->acquire('{}'));
        $this->assertStringContainsString(
            "VALUES ('" . Config::withPrefix('failure_resolved_incidents') . "', '{}', 'no')",
            $this->lastQuery()
        );
    }

    /**
     * The last SQL statement handed to $wpdb->query(), with runs of whitespace collapsed.
     */
    private function lastQuery(): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) end($this->wpdb->queries));
    }
}
