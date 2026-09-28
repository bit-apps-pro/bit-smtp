<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

/**
 * Import legacy Easy WP SMTP v1.x configuration from swpsmtp_options option.
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
     * Resolve password via swpsmtp_get_password() if available; otherwise decode base64.
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
