<?php

namespace BitApps\SMTP\Tests\Unit\Views;

use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Views\Layout;
use ReflectionClass;

/**
 * Pins the Google Fonts connection hints the admin page emits instead of fake stylesheet links.
 *
 * @internal
 *
 * @coversNothing
 */
final class LayoutTest extends BaseUnitTestCase
{
    /**
     * Preconnects to both font hosts, the file host with CORS so the font fetch can reuse it.
     */
    public function testAddsPreconnectHintsForFontHosts(): void
    {
        $hints = $this->layout()->fontResourceHints(['https://example.test'], 'preconnect');

        self::assertSame(
            [
                'https://example.test',
                ['href' => 'https://fonts.googleapis.com'],
                ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'],
            ],
            $hints
        );
    }

    /**
     * Leaves every other hint relation untouched.
     */
    public function testLeavesOtherRelationTypesUnchanged(): void
    {
        self::assertSame(['https://example.test'], $this->layout()->fontResourceHints(['https://example.test'], 'dns-prefetch'));
    }

    /**
     * Builds a Layout without its constructor, which registers WordPress hooks.
     */
    private function layout(): Layout
    {
        return (new ReflectionClass(Layout::class))->newInstanceWithoutConstructor();
    }
}
