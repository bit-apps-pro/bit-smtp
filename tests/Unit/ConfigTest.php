<?php

namespace BitApps\SMTP\Tests\Unit;

use BitApps\SMTP\Config;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

final class ConfigTest extends BaseUnitTestCase
{
    private string $baseDir;

    /** Prepare an isolated development-port file. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bit-smtp-config-' . uniqid('', true);
        mkdir($this->baseDir);
        file_put_contents($this->baseDir . '/port', '3010');

        Functions\when('plugin_dir_path')->justReturn($this->baseDir . '/');
    }

    /** Remove temporary state and proxy headers. */
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_BIT_SMTP_DEV_PROXY']);
        @unlink($this->baseDir . '/port');
        @rmdir($this->baseDir);

        parent::tearDown();
    }

    /** Use the prefixed local Vite URL for ordinary development requests. */
    public function testDevUrlUsesLocalViteBase(): void
    {
        self::assertSame('http://localhost:3010/__vite', Config::devUrl());
    }

    /** Use the current WordPress origin when requests pass through the dev proxy. */
    public function testDevUrlUsesCurrentOriginBehindDevProxy(): void
    {
        $_SERVER['HTTP_X_BIT_SMTP_DEV_PROXY'] = '1';
        Functions\when('home_url')->justReturn('https://example.trycloudflare.com/__vite');

        self::assertSame('https://example.trycloudflare.com/__vite', Config::devUrl());
    }
}
