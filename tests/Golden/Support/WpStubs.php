<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden\Support;

use Brain\Monkey\Functions;

final class WpStubs
{
    /**
     * Deterministic doubles so golden output is stable across runs/machines.
     */
    public static function install(): void
    {
        Functions\when('home_url')->alias(static fn ($p = '') => 'https://example.test' . (string) $p);
        Functions\when('rest_url')->alias(static fn ($p = '') => 'https://example.test/wp-json/' . ltrim((string) $p, '/'));
        Functions\when('wp_generate_password')->justReturn('FIXEDPASSWORD0000000000000000000000000000');
        Functions\when('wp_generate_uuid4')->justReturn('00000000-0000-0000-0000-000000000000');
        Functions\when('wp_json_encode')->alias(static fn ($v, $o = 0, $d = 512) => json_encode($v, $o, $d));
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('__')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);
    }
}
