<?php

namespace BitApps\SMTP\Tests\Unit\Views;

use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Views\Layout;
use ReflectionClass;

/**
 * Pins how the admin page marks its Vite bundles as ES modules.
 *
 * @internal
 *
 * @coversNothing
 */
final class LayoutTest extends BaseUnitTestCase
{
    /**
     * Adds type="module" to the plugin's own module script tags.
     */
    public function testMarksPluginModuleScriptsAsModules(): void
    {
        $tag = $this->layout()->scriptTagFilter('<script src="main.js"></script>', 'bit-smtp-MODULE-index', 'main.js');

        self::assertSame('<script type="module" src="main.js"></script>', $tag);
    }

    /**
     * Leaves every other script tag untouched.
     */
    public function testLeavesOtherScriptTagsUnchanged(): void
    {
        $tag = '<script src="jquery.js"></script>';

        self::assertSame($tag, $this->layout()->scriptTagFilter($tag, 'jquery', 'jquery.js'));
    }

    /**
     * Builds a Layout without its constructor, which registers WordPress hooks.
     */
    private function layout(): Layout
    {
        return (new ReflectionClass(Layout::class))->newInstanceWithoutConstructor();
    }
}
