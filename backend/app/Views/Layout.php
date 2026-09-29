<?php

// phpcs:disable Squiz.NamingConventions.ValidVariableName.NotCamelCaps

namespace BitApps\SMTP\Views;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Helpers\DateTimeHelper;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;

/**
 * The admin Layout and page handler class.
 */
class Layout
{
    public const FONT_URL = 'https://fonts.googleapis.com/css2?family=Outfit:wght@200;300;400;500;600;700&display=swap';

    private const FONT_CSS_HOST = 'https://fonts.googleapis.com';

    private const FONT_FILE_HOST = 'https://fonts.gstatic.com';

    public function __construct()
    {
        Hooks::addAction('in_admin_header', [$this, 'RemoveAdminNotices']);
        Hooks::addAction('admin_menu', [$this, 'sideBarMenuItem']);
        Hooks::addAction('admin_enqueue_scripts', [$this, 'head'], 0);
        Hooks::addFilter('script_loader_tag', [$this, 'scriptTagFilter'], 0, 3);
    }

    /**
     * Register the admin left sidebar menu item.
     */
    public function sideBarMenuItem()
    {
        $menus = Hooks::applyFilter(Config::withPrefix('admin_sidebar_menu'), Config::get('SIDE_BAR_MENU'));
        global $submenu;
        foreach ($menus as $menu) {
            if (isset($menu['capability']) && Capabilities::check($menu['capability'])) {
                if ($menu['type'] == 'menu') {
                    add_menu_page(
                        $menu['title'],
                        $menu['name'],
                        $menu['capability'],
                        $menu['slug'],
                        $menu['callback'],
                        $menu['icon'],
                        $menu['position']
                    );
                } else {
                    $submenu[$menu['parent']][] = [$menu['name'], $menu['capability'], 'admin.php?page=' . $menu['slug']];
                }
            }
        }
    }

    /**
     * Load the asset libraries.
     *
     * @param string $currentScreen $top_level_page variable for current page
     */
    public function head($currentScreen)
    {
        if (strpos($currentScreen, Config::SLUG) === false || wp_script_is(Config::SLUG . '-MODULE-index')) {
            return;
        }

        $version = Config::VERSION;
        $slug    = Config::SLUG;

        Hooks::addFilter('wp_resource_hints', [$this, 'fontResourceHints'], 10, 2);
        wp_enqueue_style('font', self::FONT_URL, [], $version);

        if (Config::isDev()) {
            wp_enqueue_script($slug . '-MODULE-vite-client-helper', Config::devUrl() . '/config/devHotModule.js', [], null);
            wp_enqueue_script($slug . '-MODULE-index', Config::devUrl() . '/main.tsx', [], null);
        } else {
            wp_enqueue_script($slug . '-MODULE-index', Config::get('ASSET_URI') . '/main.' . Config::VERSION . '.js', [], $version);
            wp_enqueue_style($slug . '-styles', Config::get('ASSET_URI') . '/main.' . Config::VERSION . '.css', null, $version, 'screen');
        }

        wp_localize_script(Config::SLUG . '-MODULE-index', Config::VAR_PREFIX, self::createConfigVariable());
    }

    public function body()
    {
        echo '<noscript>You need to enable JavaScript to run this app.</noscript>
            <div id="bit-apps-root">
              <div
                style="display: flex;flex-direction: column;justify-content: center;
                align-items: center;height: 90vh;">
                <h1>Welcome to Bit SMTP.</h1>
                <p></p>
              </div>
            </div>';
    }

    public function RemoveAdminNotices()
    {
        global $plugin_page;
        if (empty($plugin_page) || strpos($plugin_page, Config::SLUG) === false) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    /**
     * Adds preconnect hints for the Google Fonts hosts; font files are fetched with CORS, so that host needs crossorigin.
     *
     * @param array<int, array<string, string>|string> $urls         resource hints collected so far
     * @param string                                   $relationType hint relation, e.g. "preconnect"
     *
     * @return array<int, array<string, string>|string>
     */
    public function fontResourceHints($urls, $relationType)
    {
        if ($relationType !== 'preconnect') {
            return $urls;
        }

        $urls[] = ['href' => self::FONT_CSS_HOST];
        $urls[] = ['href' => self::FONT_FILE_HOST, 'crossorigin' => 'anonymous'];

        return $urls;
    }

    /**
     * Modify script tags.
     *
     * @param string $html   script tag
     * @param mixed  $handle
     * @param mixed  $href
     *
     * @return string new script tag
     */
    public function scriptTagFilter($html, $handle, $href)
    {
        $newTag = $html;
        if (strpos($handle, Config::SLUG . '-MODULE') !== false) {
            $newTag = preg_replace('/<script /', '<script type="module" ', $newTag);
        }

        return $newTag;
    }

    public function createConfigVariable()
    {
        $frontendVars = apply_filters(
            Config::withPrefix('localized_script'),
            [
                'nonce'         => wp_create_nonce('wp_rest'),
                'rootURL'       => Config::get('ROOT_URI'),
                'assetsURL'     => Config::get('ASSET_URI'),
                'baseURL'       => Config::get('ADMIN_URL') . 'admin.php?page=' . Config::SLUG . '#',
                'ajaxURL'       => admin_url('admin-ajax.php'),
                'apiURL'        => Config::get('API_URL'),
                'routePrefix'   => Config::VAR_PREFIX,
                'settings'      => Config::getOption('settings'),
                'dateFormat'    => Config::getOption('date_format', true),
                'timeFormat'    => Config::getOption('time_format', true),
                'timeZone'      => DateTimeHelper::wp_timezone_string(),
                'adButton'      => Config::adButton(),
                'sendTo'        => wp_get_current_user()->user_email,
                'testEmailSent' => (int) Config::getOption('test_mail_form_submitted') > 0,
            ]
        );
        if (get_locale() !== 'en_US' && file_exists(Config::get('BASEDIR') . '/languages/generatedString.php')) {
            include_once Config::get('BASEDIR') . '/languages/generatedString.php';
            $frontendVars['translations'] = $i18n_strings;
        }

        return $frontendVars;
    }
}
