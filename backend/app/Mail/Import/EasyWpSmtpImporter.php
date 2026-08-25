<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

/**
 * Imports a legacy "Easy WP SMTP" (v1.x) configuration. Reads the single `swpsmtp_options` option:
 * top-level `from_email_field` / `from_name_field`, and the `smtp_settings` sub-array (`host`,
 * `port`, `type_encryption` [none|ssl|tls], `autentication` ['yes'|'no'] — the plugin's real,
 * misspelled key — `username`, `password` [base64-encoded]). No reply-to setting exists.
 * Source: easy-wp-smtp.php (option `swpsmtp_options`, function swpsmtp_get_password()).
 */
final class EasyWpSmtpImporter extends AbstractSmtpImporter
{
    private const OPTION = 'swpsmtp_options';

    public function key(): string
    {
        return 'easy_wp_smtp';
    }

    public function label(): string
    {
        return 'Easy WP SMTP';
    }

    /**
     * Importable once an SMTP host has been configured.
     */
    public function detect(): bool
    {
        return (string) ($this->smtpSettings()['host'] ?? '') !== '';
    }

    public function toConnection(): ?array
    {
        if (!$this->detect()) {
            return null;
        }

        $option = $this->optionArray(self::OPTION);
        $smtp   = $this->smtpSettings();

        return $this->toSmtpConnection([
            'name'       => 'Imported from ' . $this->label(),
            'fromEmail'  => (string) ($option['from_email_field'] ?? ''),
            'fromName'   => (string) ($option['from_name_field'] ?? ''),
            'host'       => (string) ($smtp['host'] ?? ''),
            'port'       => (int) ($smtp['port'] ?? 0),
            'encryption' => $smtp['type_encryption'] ?? '',
            'auth'       => strtolower(trim((string) ($smtp['autentication'] ?? 'no'))) === 'yes',
            'username'   => (string) ($smtp['username'] ?? ''),
            'password'   => $this->resolvePassword($smtp),
        ]);
    }

    /**
     * Easy WP SMTP base64-encodes the stored password (optionally AES-encrypting it first, gated behind
     * its own key). Its global swpsmtp_get_password() reverses whichever form is in play, so we defer to
     * it when loaded. Without it (source plugin inactive) we can only safely handle the no-AES case:
     * base64_decode yields the plaintext, which is valid UTF-8 text. An AES password is stored as
     * base64(ciphertext) — also clean base64, but decodes to binary, so a non-UTF-8 result is treated as
     * unreversible ciphertext and dropped rather than persisted broken. Source: easy-wp-smtp.php.
     *
     * @param array<string,mixed> $smtp
     */
    private function resolvePassword(array $smtp): string
    {
        if (\function_exists('swpsmtp_get_password')) {
            $decoded = swpsmtp_get_password();
            if (\is_string($decoded)) {
                return $decoded;
            }
        }

        $raw = (string) ($smtp['password'] ?? '');
        if ($raw === '') {
            return '';
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false || base64_encode($decoded) !== $raw) {
            return '';
        }

        // Clean base64: a no-AES password decodes to UTF-8 text; AES ciphertext decodes to binary.
        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function smtpSettings(): array
    {
        return $this->subArray($this->optionArray(self::OPTION), 'smtp_settings');
    }
}
