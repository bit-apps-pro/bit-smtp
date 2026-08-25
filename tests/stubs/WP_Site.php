<?php

/**
 * Minimal WP_Site stub for the unit tier (real WP is only loaded in the integration tier).
 * Mirrors the one property provisioning relies on: the new site's numeric blog id.
 */
if (!class_exists('WP_Site')) {
    final class WP_Site
    {
        /**
         * @var int|string
         */
        public $blog_id;
    }
}
