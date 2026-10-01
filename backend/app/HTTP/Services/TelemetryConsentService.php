<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Report\Report;

\defined('ABSPATH') || exit();

/**
 * Records the site owner's one-time usage-data sharing answer on top of the wp-telemetry library.
 */
class TelemetryConsentService
{
    /**
     * Set once any answer is given; survives deactivation, unlike the library's notice-dismissed flag.
     */
    public const ANSWERED_OPTION = Config::VAR_PREFIX . 'telemetry_prompt_answered';

    /**
     * Options earlier plugin versions or the library's admin notice wrote when the owner answered.
     */
    private const LEGACY_ANSWER_OPTIONS = [
        Config::VAR_PREFIX . 'old_version',
        Config::VAR_PREFIX . 'tracking_skipped',
        Config::VAR_PREFIX . 'tracking_notice_dismissed',
    ];

    private const ALLOW_OPTION = Config::VAR_PREFIX . 'allow_tracking';

    private const NOTICE_DISMISSED_OPTION = Config::VAR_PREFIX . 'tracking_notice_dismissed';

    private const REPORT_CRON_HOOK = Config::VAR_PREFIX . 'send_tracking_event';

    private Report $report;

    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    /**
     * Whether the owner has answered and whether sharing is currently on.
     *
     * @return array{answered: bool, allowed: bool}
     */
    public function status(): array
    {
        return ['answered' => $this->isAnswered(), 'allowed' => $this->isAllowed()];
    }

    /**
     * Turn sharing on through the library (schedules and sends the weekly report) and remember the answer.
     *
     * @return array{answered: bool, allowed: bool}
     */
    public function allow(): array
    {
        $this->report->trackingOptIn();
        $this->markAnswered();

        return $this->status();
    }

    /**
     * Turn sharing off locally; skips the library opt-out because it phones home a "skipped" ping.
     *
     * @return array{answered: bool, allowed: bool}
     */
    public function decline(): array
    {
        update_option(self::ALLOW_OPTION, false);
        update_option(self::NOTICE_DISMISSED_OPTION, true);
        wp_clear_scheduled_hook(self::REPORT_CRON_HOOK);
        $this->markAnswered();

        return $this->status();
    }

    /**
     * Whether the owner already answered here, in an older version, or via the library notice.
     */
    private function isAnswered(): bool
    {
        if (get_option(self::ANSWERED_OPTION) || $this->isAllowed()) {
            return true;
        }

        foreach (self::LEGACY_ANSWER_OPTIONS as $option) {
            if (get_option($option)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the library currently has sharing switched on.
     */
    private function isAllowed(): bool
    {
        return (bool) $this->report->isTrackingAllowed();
    }

    /**
     * Persist that the owner answered so the prompt never shows again.
     */
    private function markAnswered(): void
    {
        update_option(self::ANSWERED_OPTION, true, false);
    }
}
