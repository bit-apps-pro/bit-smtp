<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class MailSourceDetectorTest extends BaseUnitTestCase
{
    private MailSourceDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new MailSourceDetector();
    }

    public function testFirstExternalFrameIsAPlugin(): void
    {
        $frames = [
            ['file' => '/var/www/html/wp-includes/pluggable.php', 'function' => 'wp_mail'],
            ['file' => '/var/www/html/wp-content/plugins/bit-smtp/backend/app/Mail/Dispatch/WpMailBridge.php', 'function' => 'send'],
            ['file' => '/var/www/html/wp-content/plugins/woocommerce/includes/emails/class-wc-email.php', 'function' => 'send'],
        ];
        $selfDir = '/var/www/html/wp-content/plugins/bit-smtp';

        $result = $this->detector->detect($frames, $selfDir);

        $this->assertSame('woocommerce', $result);
    }

    public function testOnlyCoreAndSelfFramesReturnsUnknown(): void
    {
        $frames = [
            ['file' => '/var/www/html/wp-includes/pluggable.php', 'function' => 'wp_mail'],
            ['file' => '/var/www/html/wp-admin/includes/file.php', 'function' => 'some_admin_func'],
            ['file' => '/var/www/html/wp-content/plugins/bit-smtp/backend/app/Mail/Routing/MailSourceDetector.php', 'function' => 'detect'],
        ];
        $selfDir = '/var/www/html/wp-content/plugins/bit-smtp';

        $result = $this->detector->detect($frames, $selfDir);

        $this->assertSame('unknown', $result);
    }

    public function testThemeFrameIsClassified(): void
    {
        $frames = [
            ['file' => '/var/www/html/wp-content/themes/twentytwentyfour/functions.php', 'function' => 'wp_theme_json_data_default'],
        ];

        $result = $this->detector->detect($frames, null);

        $this->assertSame('theme:twentytwentyfour', $result);
    }

    public function testMuPluginFrameIsClassified(): void
    {
        $frames = [
            ['file' => '/var/www/html/wp-content/mu-plugins/my-mu.php', 'function' => 'do_something'],
        ];

        $result = $this->detector->detect($frames, null);

        $this->assertSame('mu:my-mu', $result);
    }

    public function testFrameMissingFileKeyIsSkippedSafely(): void
    {
        $frames = [
            ['function' => 'do_action'],
            ['file' => '/var/www/html/wp-content/plugins/edd/includes/emails/functions.php', 'function' => 'send_email'],
        ];

        $result = $this->detector->detect($frames, null);

        $this->assertSame('edd', $result);
    }

    public function testWindowsStylePathsClassifyTheSame(): void
    {
        $frames = [
            ['file' => 'C:\\wamp\\www\\wp-content\\plugins\\woocommerce\\includes\\emails\\class-wc-email.php', 'function' => 'send'],
        ];
        $selfDir = 'C:\\wamp\\www\\wp-content\\plugins\\bit-smtp';

        $result = $this->detector->detect($frames, $selfDir);

        $this->assertSame('woocommerce', $result);
    }
}
