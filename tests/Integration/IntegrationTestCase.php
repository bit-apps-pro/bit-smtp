<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Settings\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * Base for integration tests. Real WordPress (wp-phpunit) is loaded by the bootstrap; the
 * ephemeral docker `db` and `mailpit` services back the run. Extends plain TestCase because
 * wp-phpunit's WP_UnitTestCase is not PHPUnit 12 compatible; DB state is reset per test here.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const MAILPIT_API = 'http://127.0.0.1:8025/api/v1';

    protected const SMTP_HOST = '127.0.0.1';

    protected const SMTP_PORT = 1025;

    protected function setUp(): void
    {
        parent::setUp();
        Config::deleteOption('options');
        Config::deleteOption('failure_notification_active');
        // Preferences are seeded once at install time and otherwise untouched by this reset, so a
        // test that relies on the legacy-option fallback (no blob written yet) needs a clean slate.
        delete_option(PluginSettings::OPTION_NAME);
        $this->clearMailpit();
    }

    /**
     * Persist the plugin option (legacy flat array or v2 schema).
     *
     * @param array<string,mixed> $options
     */
    protected function storeOptions(array $options): void
    {
        Config::updateOption('options', $options);
    }

    protected function clearMailpit(): void
    {
        wp_remote_request(self::MAILPIT_API . '/messages', ['method' => 'DELETE']);
    }

    /**
     * Swap wp-phpunit's MockPHPMailer (which captures mail without sending) for a real PHPMailer,
     * so wp_mail() performs a genuine SMTP send to mailpit through our phpmailer_init config.
     */
    protected function useRealPhpMailer(): void
    {
        // WP loads PHPMailer/SMTP lazily; require them so phpmailer_init can reference SMTP constants.
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        global $phpmailer;
        $phpmailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    }

    /**
     * @return array<int,array<string,mixed>> Mailpit message summaries, newest first
     */
    protected function mailpitMessages(): array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/messages');
        $body     = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['messages']) && \is_array($body['messages']) ? $body['messages'] : [];
    }

    /**
     * @return array<string,mixed>|null Full latest delivered message, or null when the box is empty
     */
    protected function latestMailpitMessage(): ?array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/latest');
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * @return array<string,array<int,string>> Raw headers of the delivered message, keyed by name
     */
    protected function mailpitHeaders(string $id): array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/' . $id . '/headers');
        $headers  = json_decode(wp_remote_retrieve_body($response), true);

        return \is_array($headers) ? $headers : [];
    }
}
