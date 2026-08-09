<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class LogServiceTest extends BaseUnitTestCase
{
    public function testDisablingLoggingPersistsAZeroValueSoWordPressCreatesTheFlag(): void
    {
        $options = [
            'bit_smtp_logging_enabled'         => 1,
            'bit_smtp_logging_continuity_from' => '2026-03-01 00:00:00',
        ];
        Functions\when('get_option')->alias(static function (string $key, $default) use (&$options) {
            return $options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options): bool {
            $options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(static function (string $key) use (&$options): bool {
            unset($options[$key]);

            return true;
        });

        self::assertTrue((new LogService())->setEnabled(false));
        self::assertSame(0, $options['bit_smtp_logging_enabled']);
        self::assertArrayNotHasKey('bit_smtp_logging_continuity_from', $options);
    }
}
