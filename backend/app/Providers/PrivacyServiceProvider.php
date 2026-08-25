<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Privacy\EngagementDataEraser;
use BitApps\SMTP\Privacy\EngagementDataExporter;

\defined('ABSPATH') || exit();

/**
 * Wires the plugin into WordPress's privacy tools: a personal-data exporter and eraser for
 * open/click engagement events (keyed by recipient email) plus suggested privacy-policy copy. The
 * filters carry no load-time side effect, so registration is safe at boot(); policy content must be
 * added on admin_init per wp_add_privacy_policy_content()'s contract.
 */
final class PrivacyServiceProvider extends ServiceProvider
{
    /**
     * Shared exporter/eraser slug WordPress reports the group and request under.
     */
    private const ENGAGEMENT_KEY = 'bit-smtp-engagement';

    /**
     * No container bindings; the privacy callbacks are self-contained.
     */
    public function register(): void
    {
    }

    /**
     * Register the export/erase filters and admin-only policy content.
     */
    public function boot(): void
    {
        Hooks::addFilter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        Hooks::addFilter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        Hooks::addAction('admin_init', [$this, 'registerPolicyContent']);
    }

    /**
     * Append the engagement exporter to WordPress's exporter registry.
     *
     * @param array<string,mixed> $exporters
     *
     * @return array<string,mixed>
     */
    public function registerExporter(array $exporters): array
    {
        $exporters[self::ENGAGEMENT_KEY] = [
            'exporter_friendly_name' => __('Bit SMTP email open & click tracking', 'bit-smtp'),
            'callback'               => [new EngagementDataExporter(), 'export'],
        ];

        return $exporters;
    }

    /**
     * Append the engagement eraser to WordPress's eraser registry.
     *
     * @param array<string,mixed> $erasers
     *
     * @return array<string,mixed>
     */
    public function registerEraser(array $erasers): array
    {
        $erasers[self::ENGAGEMENT_KEY] = [
            'eraser_friendly_name' => __('Bit SMTP email open & click tracking', 'bit-smtp'),
            'callback'             => [new EngagementDataEraser(), 'erase'],
        ];

        return $erasers;
    }

    /**
     * Add the suggested privacy-policy paragraph describing open/click tracking.
     */
    public function registerPolicyContent(): void
    {
        wp_add_privacy_policy_content(Config::TITLE, $this->policyContent());
    }

    /**
     * Honest, off-by-default description of what engagement tracking collects and how long it lives.
     */
    private function policyContent(): string
    {
        return wp_kses_post(wpautop(
            __(
                'When open and click tracking is enabled (it is off by default), outgoing HTML emails include an invisible tracking pixel and their links are rewritten so this site can record when a message is opened and which links are clicked. These events are stored against the corresponding email log entry — tied to the recipient email address — and are retained for as long as your email log retention setting keeps that entry. Recorded opens and clicks can be exported or erased on request through the built-in personal-data tools.',
                'bit-smtp'
            )
        ));
    }
}
