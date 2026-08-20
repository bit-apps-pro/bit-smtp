<?php

namespace BitApps\SMTP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class BaseUnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Passthrough stubs for the WP escaping/URL helpers the code now routes through; individual
        // tests may still redefine any of these with their own expectation.
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('wp_parse_url')->alias(static fn (...$args) => \parse_url(...$args));
        Functions\when('wp_strip_all_tags')->alias(static fn ($string) => \strip_tags((string) $string));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
