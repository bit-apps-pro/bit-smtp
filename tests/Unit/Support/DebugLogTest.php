<?php

namespace BitApps\SMTP\Tests\Unit\Support;

use BitApps\SMTP\Support\DebugLog;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DebugLogTest extends BaseUnitTestCase
{
    /**
     * With debug logging on, the message reaches the error log under the plugin prefix.
     */
    public function testWritesThePrefixedMessageWhenDebugLoggingIsOn(): void
    {
        $this->expectOutputRegex('/Bit SMTP: retry row 7 dropped/');

        DebugLog::write('retry row 7 dropped');
    }

    /**
     * Data-loss errors reach the error log under the plugin prefix.
     */
    public function testErrorWritesThePrefixedMessage(): void
    {
        $this->expectOutputRegex('/Bit SMTP: secret could not be decrypted/');

        DebugLog::error('secret could not be decrypted');
    }
}
