<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\MailSourceLabeler;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class MailSourceLabelerTest extends BaseUnitTestCase
{
    public function testMapsPluginDirectorySlugsToTheirHeaderNames(): void
    {
        $labeler = new MailSourceLabeler([
            'woocommerce/woocommerce.php' => ['Name' => 'WooCommerce'],
            'bit-form/bit-form.php'       => ['Name' => 'Bit Form'],
        ]);

        self::assertSame([
            ['value' => 'woocommerce', 'label' => 'WooCommerce'],
            ['value' => 'bit-form', 'label' => 'Bit Form'],
        ], $labeler->options(['woocommerce', 'bit-form']));
    }

    public function testFallsBackToTheRawSlugForUndetectedAndPrefixedSources(): void
    {
        $labeler = new MailSourceLabeler([
            'woocommerce/woocommerce.php' => ['Name' => 'WooCommerce'],
        ]);

        self::assertSame([
            ['value' => 'undetected-plugin', 'label' => 'undetected-plugin'],
            ['value' => 'mu:drop-in', 'label' => 'mu:drop-in'],
            ['value' => 'theme:twentytwenty', 'label' => 'theme:twentytwenty'],
        ], $labeler->options(['undetected-plugin', 'mu:drop-in', 'theme:twentytwenty']));
    }

    public function testUsesTheDirectorySlugWhenAPluginHeaderNameIsMissing(): void
    {
        $labeler = new MailSourceLabeler([
            'no-name/no-name.php' => ['Name' => ''],
        ]);

        self::assertSame([['value' => 'no-name', 'label' => 'no-name']], $labeler->options(['no-name']));
    }

    public function testInstalledListsFolderPluginsSortedByLabelWithoutExcludedSlugs(): void
    {
        $labeler = new MailSourceLabeler([
            'woocommerce/woocommerce.php' => ['Name' => 'WooCommerce'],
            'bit-crm/bit-crm.php'         => ['Name' => 'Bit CRM'],
            'bit-smtp/bit_smtp.php'       => ['Name' => 'Bit SMTP'],
            'bit-form/bit-form.php'       => ['Name' => 'Bit Form'],
            'akismet/akismet.php'         => ['Name' => 'akismet Anti-spam'],
        ]);

        self::assertSame([
            ['value' => 'akismet', 'label' => 'akismet Anti-spam'],
            ['value' => 'bit-crm', 'label' => 'Bit CRM'],
            ['value' => 'woocommerce', 'label' => 'WooCommerce'],
        ], $labeler->installed(['bit-smtp', 'bit-form']));
    }

    public function testInstalledSkipsSingleFilePluginsTheDetectorCannotAttribute(): void
    {
        $labeler = new MailSourceLabeler([
            'hello.php'           => ['Name' => 'Hello Dolly'],
            'bit-crm/bit-crm.php' => ['Name' => 'Bit CRM'],
        ]);

        self::assertSame([['value' => 'bit-crm', 'label' => 'Bit CRM']], $labeler->installed([]));
    }

    public function testInstalledListsAMultiFilePluginDirectoryOnce(): void
    {
        $labeler = new MailSourceLabeler([
            'bit-pi/bit-pi.php'     => ['Name' => 'Bit Flows'],
            'bit-pi/bit-pi-pro.php' => ['Name' => 'Bit Flows Pro'],
        ]);

        self::assertSame([['value' => 'bit-pi', 'label' => 'Bit Flows']], $labeler->installed([]));
    }
}
