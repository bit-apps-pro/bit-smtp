<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Settings\OptionSwap;
use wpdb;

/**
 * Tests OptionSwap and failure-notification gate with concurrent writes via independent DB connection.
 *
 * @internal
 *
 * @coversNothing
 */
final class OptionSwapTest extends IntegrationTestCase
{
    private const OPTION = 'bit_smtp_swap_probe';

    private wpdb $otherConnection;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(self::OPTION);
        $this->otherConnection = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->otherConnection->set_prefix($GLOBALS['wpdb']->prefix);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        $this->otherConnection->close();
        parent::tearDown();
    }

    public function testCreatedRowLandsInTheCoreOptionsTableWithTheRequestedAutoload(): void
    {
        global $wpdb;

        $this->assertTrue(OptionSwap::create(self::OPTION, 'v1', OptionSwap::NO_AUTOLOAD));

        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        $this->assertSame('v1', $row->option_value);
        $this->assertSame('no', $row->autoload);
        $this->assertSame('v1', OptionSwap::read(self::OPTION));
    }

    public function testCreateLosesToARowAnotherConnectionInsertedFirst(): void
    {
        $this->otherConnection->insert($this->otherConnection->options, ['option_name' => self::OPTION, 'option_value' => 'theirs', 'autoload' => 'no']);

        $this->assertFalse(OptionSwap::create(self::OPTION, 'mine'));
        $this->assertSame('theirs', OptionSwap::read(self::OPTION));
    }

    public function testReplaceLosesWhenAnotherConnectionChangedTheRowAfterItWasRead(): void
    {
        OptionSwap::create(self::OPTION, 'v1');
        $read = OptionSwap::read(self::OPTION);

        $this->otherConnection->update($this->otherConnection->options, ['option_value' => 'theirs'], ['option_name' => self::OPTION]);

        $this->assertFalse(OptionSwap::replace(self::OPTION, $read, 'mine'));
        $this->assertSame('theirs', OptionSwap::read(self::OPTION));
        $this->assertSame('theirs', get_option(self::OPTION), 'a lost swap must still drop the stale cached copy');
    }

    public function testDeleteLosesWhenAnotherConnectionChangedTheRowAfterItWasRead(): void
    {
        OptionSwap::create(self::OPTION, 'v1');
        $read = OptionSwap::read(self::OPTION);

        $this->otherConnection->update($this->otherConnection->options, ['option_value' => 'v2'], ['option_name' => self::OPTION]);

        $this->assertFalse(OptionSwap::delete(self::OPTION, $read));
        $this->assertTrue(OptionSwap::delete(self::OPTION, 'v2'));
        $this->assertNull(OptionSwap::read(self::OPTION));
        $this->assertFalse(get_option(self::OPTION));
    }

    public function testComparisonIsByteExactDespiteTheCaseInsensitiveCollation(): void
    {
        OptionSwap::create(self::OPTION, 'Token');

        $this->assertFalse(OptionSwap::replace(self::OPTION, 'token', 'next'));
        $this->assertFalse(OptionSwap::delete(self::OPTION, 'TOKEN'));
        $this->assertTrue(OptionSwap::replace(self::OPTION, 'Token', 'next'));
    }

    public function testSuccessfulSwapIsVisibleThroughTheCachedOptionApi(): void
    {
        OptionSwap::create(self::OPTION, 'v1');
        $this->assertSame('v1', get_option(self::OPTION));

        $this->assertTrue(OptionSwap::replace(self::OPTION, 'v1', 'v2'));
        $this->assertSame('v2', get_option(self::OPTION));
    }

    public function testOnlyOneOfTwoGatesAcquiresTheIncidentRow(): void
    {
        $this->assertTrue((new FailureNotificationGate())->acquire('incident-1'));
        $this->assertFalse((new FailureNotificationGate())->acquire('incident-2'));
        $this->assertSame('incident-1', Config::getOption(FailureNotificationGate::OPTION));
    }

    public function testStaleGateResetCannotDeleteAnIncidentOpenedByAnotherConnection(): void
    {
        $gate = new FailureNotificationGate();
        $gate->acquire('incident-first');
        $snapshot = $gate->read();

        $this->otherConnection->update(
            $this->otherConnection->options,
            ['option_value' => 'incident-next'],
            ['option_name'  => Config::withPrefix(FailureNotificationGate::OPTION)]
        );

        $this->assertFalse($gate->delete($snapshot));
        $this->assertSame('incident-next', $gate->reset());
        $this->assertNull($gate->read());
        $this->assertNull($gate->reset());
    }
}
