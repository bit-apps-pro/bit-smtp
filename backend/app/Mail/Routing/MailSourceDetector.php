<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

/**
 * Attribute an outgoing email to the plugin, mu-plugin, or theme that called wp_mail().
 */
class MailSourceDetector
{
    /**
     * Source of the first non-core, non-Bit-SMTP caller: plugin slug, 'mu:<file>', 'theme:<slug>', or 'unknown'.
     */
    public function detect(?array $frames = null, ?string $selfDir = null): string
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attributes mail to the calling plugin.
        $frames  = $frames ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $selfDir = $this->normalize($selfDir ?? \dirname(__DIR__, 4));

        foreach ($frames as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $path = $this->normalize($frame['file']);

            if ($this->isCore($path) || $this->isSelf($path, $selfDir)) {
                continue;
            }

            return $this->classify($path);
        }

        return 'unknown';
    }

    private function isCore(string $path): bool
    {
        return strpos($path, '/wp-includes/') !== false || strpos($path, '/wp-admin/') !== false;
    }

    private function isSelf(string $path, string $selfDir): bool
    {
        $selfDir = rtrim($selfDir, '/');

        return $path === $selfDir || strpos($path, $selfDir . '/') === 0;
    }

    private function classify(string $path): string
    {
        if (preg_match('#/wp-content/plugins/([^/]+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (strpos($path, '/wp-content/mu-plugins/') !== false) {
            return 'mu:' . pathinfo($path, PATHINFO_FILENAME);
        }

        if (preg_match('#/wp-content/themes/([^/]+)/#', $path, $matches) === 1) {
            return 'theme:' . $matches[1];
        }

        return 'unknown';
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
