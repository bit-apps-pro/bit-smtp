<?php

namespace BitApps\SMTP\Tests\Integration;

final class SmokeTest extends IntegrationTestCase
{
    public function test_wordpress_and_plugin_loaded(): void
    {
        $this->assertTrue(function_exists('wp_mail'));
        $this->assertTrue(class_exists(\BitApps\SMTP\Plugin::class));
    }

    public function test_mailpit_is_reachable_and_reset_between_tests(): void
    {
        $this->assertSame([], $this->mailpitMessages());
    }
}
