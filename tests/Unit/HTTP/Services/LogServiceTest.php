<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class LogServiceTest extends BaseUnitTestCase
{
    public function testDisablingLoggingPersistsAZeroValueSoWordPressCreatesTheFlag(): void
    {
        Functions\expect('update_option')
            ->once()
            ->with('bit_smtp_logging_enabled', Mockery::on(static fn ($value): bool => $value === 0), 'yes')
            ->andReturn(true);

        self::assertTrue((new LogService())->setEnabled(false));
    }
}
