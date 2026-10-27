<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\MailSourceController;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Model\Log;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailSourceControllerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
    }

    public function testDistinctSourcePluginsReturnsDeduplicatedRealSendersInAlphabeticalOrder(): void
    {
        $this->seed('woocommerce');
        $this->seed('woocommerce');
        $this->seed('bit-form');
        $this->seed('contact-form-7');
        $this->seed('unknown');
        $this->seed('');
        $this->seed(null);

        $slugs = (new MailAnalyticsRepository())->distinctSourcePlugins();

        self::assertSame(['bit-form', 'contact-form-7', 'woocommerce'], $slugs);
    }

    public function testControllerReturnsDistinctSourcesWithValueAndLabelKeys(): void
    {
        $this->seed('woocommerce');
        $this->seed('bit-form');
        $this->seed('unknown');

        (new MailSourceController(new MailAnalyticsRepository()))->index();
        $data = (array) Response::getData();

        self::assertSame(Response::SUCCESS, Response::getStatus());
        self::assertArrayHasKey('sources', $data);
        $values = array_column($data['sources'], 'value');
        self::assertSame(['bit-form', 'woocommerce'], $values);
        foreach ($data['sources'] as $source) {
            self::assertArrayHasKey('value', $source);
            self::assertArrayHasKey('label', $source);
            self::assertNotSame('', $source['label']);
        }
    }

    public function testControllerListsInstalledPluginsExceptSeenSourcesAndItself(): void
    {
        $installed = array_values(array_unique(array_map(
            static fn (string $file): string => \dirname($file),
            array_filter(array_keys(get_plugins()), static fn (string $file): bool => str_contains($file, '/'))
        )));
        $seen = array_values(array_diff($installed, ['bit-smtp']))[0] ?? null;
        if ($seen === null) {
            self::markTestSkipped('Needs at least one installed plugin besides Bit SMTP.');
        }
        $this->seed($seen);

        (new MailSourceController(new MailAnalyticsRepository()))->index();
        $values = array_column(((array) Response::getData())['installed'], 'value');

        self::assertNotContains($seen, $values);
        self::assertNotContains('bit-smtp', $values);
        self::assertEqualsCanonicalizing(array_values(array_diff($installed, [$seen, 'bit-smtp'])), $values);
    }

    public function testRoutingSourcesRouteIsRegisteredUnderTheAdminGuardedApi(): void
    {
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();
        $prefix = '/' . \BitApps\SMTP\Config::SLUG . '/v' . \BitApps\SMTP\Config::API_VERSION;

        self::assertArrayHasKey($prefix . '/mail/routing/sources', $routes);
    }

    private function seed(?string $source): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'         => 1,
                'subject'        => 'Retained raw subject must not be selected',
                'to_addr'        => wp_json_encode(['customer@example.test']),
                'source_plugin'  => $source,
                'created_at'     => '2026-03-01 05:00:00',
                'created_at_utc' => '2026-03-01 05:00:00',
                'updated_at'     => '2026-03-01 05:00:00',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        self::assertSame(1, $inserted);
    }
}
