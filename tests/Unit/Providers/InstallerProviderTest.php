<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Providers;

use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_Site;

/**
 * Covers the wp_initialize_site provisioning callback: a subsite created after network activation is
 * given the plugin schema, but only on multisite and only when the plugin is network-active.
 *
 * @internal
 *
 * @coversNothing
 */
final class InstallerProviderTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('register_activation_hook')->justReturn(true);
        Functions\when('register_deactivation_hook')->justReturn(true);
        Functions\when('register_uninstall_hook')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('bit-smtp/bit_smtp.php');
        Functions\when('plugin_dir_path')->justReturn('/tmp/bit-smtp/');
    }

    public function testSkipsProvisioningWhenNotMultisite(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\expect('switch_to_blog')->never();
        Functions\expect('restore_current_blog')->never();

        (new InstallerProvider())->provisionNewSite($this->site(7));
    }

    public function testSkipsProvisioningWhenPluginIsNotNetworkActive(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('is_plugin_active_for_network')->justReturn(false);
        Functions\expect('switch_to_blog')->never();
        Functions\expect('restore_current_blog')->never();

        (new InstallerProvider())->provisionNewSite($this->site(7));
    }

    public function testProvisionsSchemaOnTheNewBlogWhenNetworkActive(): void
    {
        // Alias-mock the static migration helper: its real file hard-requires wp-admin/includes and so
        // is never loadable in the unit tier, which makes aliasing safe (no real class competes).
        $migrationHelper = Mockery::mock('alias:BitApps\SMTP\Deps\BitApps\WPKit\Migration\MigrationHelper');
        $migrationHelper->shouldReceive('migrate')->once()->with(Mockery::type('array'));
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('is_plugin_active_for_network')->justReturn(true);
        $switchedTo = null;
        Functions\when('switch_to_blog')->alias(static function ($blogId) use (&$switchedTo): bool {
            $switchedTo = $blogId;

            return true;
        });
        $restored = false;
        Functions\when('restore_current_blog')->alias(static function () use (&$restored): bool {
            $restored = true;

            return true;
        });

        (new InstallerProvider())->provisionNewSite($this->site(7));

        self::assertSame(7, $switchedTo);
        self::assertTrue($restored);
    }

    private function site(int $blogId): WP_Site
    {
        $site          = new WP_Site();
        $site->blog_id = $blogId;

        return $site;
    }
}
