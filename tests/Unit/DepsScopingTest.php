<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DepsScopingTest extends TestCase
{
    /**
     * Confirms the imposter plugin rewrote wp-kit's namespaces into BitApps\SMTP\Deps\... inside vendor/.
     */
    public function testContainerIsScoped(): void
    {
        self::assertTrue(class_exists(\BitApps\SMTP\Deps\BitApps\WPKit\Container\Container::class));
    }

    public function testCacheManagerIsScoped(): void
    {
        self::assertTrue(class_exists(\BitApps\SMTP\Deps\BitApps\WPKit\Cache\CacheManager::class));
    }

    public function testSettingsRepositoryIsScoped(): void
    {
        self::assertTrue(class_exists(\BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository::class));
    }

    public function testSchedulerIsScoped(): void
    {
        self::assertTrue(class_exists(\BitApps\SMTP\Deps\BitApps\WPKit\Cron\Scheduler::class));
    }
}
