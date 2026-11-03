<?php

namespace BitApps\SMTP\CLI;

\defined('ABSPATH') || exit();

/**
 * A WP-CLI command that publishes its own help text (short description and argument synopsis).
 */
interface DescribedCommand
{
    /**
     * One-line summary shown in `wp help` and at the top of `--help`.
     */
    public const SHORTDESC = '';

    /**
     * Arguments in WP-CLI synopsis format (type, name, description, optional, default, options).
     *
     * @var list<array<string,mixed>>
     */
    public const SYNOPSIS = [];
}
