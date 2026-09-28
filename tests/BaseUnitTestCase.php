<?php

namespace BitApps\SMTP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class BaseUnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up passthrough stubs for WP helpers; tests can override individual stubs as needed.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('wp_parse_url')->alias(static fn (...$args) => parse_url(...$args));
        Functions\when('sanitize_text_field')->alias(static fn ($string) => trim(strip_tags((string) $string)));
        Functions\when('wp_strip_all_tags')->alias(static fn ($string) => strip_tags((string) $string));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
