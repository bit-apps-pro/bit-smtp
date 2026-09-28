<?php

namespace BitApps\SMTP\Tests\Integration;

/**
 * @internal
 *
 * @coversNothing
 */
final class SmokeTest extends IntegrationTestCase
{
    public function testWordpressAndPluginLoaded(): void
    {
        $this->assertTrue(\function_exists('wp_mail'));
        $this->assertTrue(class_exists(\BitApps\SMTP\Plugin::class));
    }

    public function testMailpitIsReachableAndResetBetweenTests(): void
    {
        $this->assertSame([], $this->mailpitMessages());
    }
}
