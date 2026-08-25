<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

/**
 * Reads another SMTP plugin's stored WordPress settings and maps them onto a Bit SMTP connection so
 * a migrating user does not have to reconfigure by hand. One implementation per source plugin.
 */
interface PluginImporterInterface
{
    /**
     * Stable machine slug used on the CLI (e.g. `wp_mail_smtp`); never localized.
     */
    public function key(): string;

    /**
     * Human-readable source-plugin name for listings (e.g. `WP Mail SMTP`).
     */
    public function label(): string;

    /**
     * True only when the source plugin holds an importable SMTP configuration on this site.
     */
    public function detect(): bool;

    /**
     * Map the source settings onto a Bit SMTP connection array, or null when nothing importable.
     *
     * @return null|array<string,mixed>
     */
    public function toConnection(): ?array;
}
