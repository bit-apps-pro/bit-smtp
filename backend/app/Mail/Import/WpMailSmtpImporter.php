<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

/**
 * Imports a "WP Mail SMTP" (by WPForms) SMTP-mailer configuration. Reads the single `wp_mail_smtp`
 * option: the `mail` group (`from_email`, `from_name`, `mailer`, `reply_to_email`) and the `smtp`
 * group (`host`, `port`, `encryption`, `auth`, `user`, `pass`). Encryption values are `none|ssl|tls`.
 * Only the `smtp` mailer is imported; API mailers (sendgrid/mailgun/ses/...) are out of scope for
 * this first cut. The `mail.return_path` boolean ("set Return-Path to match From") is intentionally
 * not imported: Bit SMTP has no per-connection return-path setting to map it onto. Source: WP Mail
 * SMTP src/Options.php (META_KEY `wp_mail_smtp`, groups mail/smtp).
 */
final class WpMailSmtpImporter extends AbstractSmtpImporter
{
    private const OPTION = 'wp_mail_smtp';

    public function key(): string
    {
        return 'wp_mail_smtp';
    }

    public function label(): string
    {
        return 'WP Mail SMTP';
    }

    /**
     * Importable only when the active mailer is `smtp` and a host is set.
     */
    public function detect(): bool
    {
        return $this->mailer() === 'smtp' && $this->host() !== '';
    }

    public function toConnection(): ?array
    {
        if (!$this->detect()) {
            return null;
        }

        $mail = $this->subArray($this->optionArray(self::OPTION), 'mail');
        $smtp = $this->smtpGroup();

        return $this->toSmtpConnection([
            'name'         => 'Imported from ' . $this->label(),
            'fromEmail'    => (string) ($mail['from_email'] ?? ''),
            'fromName'     => (string) ($mail['from_name'] ?? ''),
            'replyToEmail' => (string) ($mail['reply_to_email'] ?? ''),
            'host'         => (string) ($smtp['host'] ?? ''),
            'port'         => (int) ($smtp['port'] ?? 0),
            'encryption'   => $smtp['encryption'] ?? '',
            'auth'         => (bool) filter_var($smtp['auth'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'username'     => (string) ($smtp['user'] ?? ''),
            'password'     => $this->resolvePassword($smtp),
        ]);
    }

    /**
     * WP Mail SMTP >= 3.3 encrypts `smtp.pass` at rest (Crypto) and also honors the `WPMS_SMTP_PASS`
     * constant; its own Options API returns the decrypted / constant-resolved value, so we defer to it
     * rather than reimplement (or mishandle) their crypto. Source: WP Mail SMTP src/Options.php.
     * When that class is unavailable (source plugin deactivated) the raw value may be ciphertext we
     * cannot safely reverse, so we return '' rather than persist a broken credential — the connection
     * imports without a password for the user to set (better than a silently unusable one).
     *
     * @param array<string,mixed> $smtp
     */
    private function resolvePassword(array $smtp): string
    {
        if (class_exists('\WPMailSMTP\Options')) {
            $decrypted = \WPMailSMTP\Options::init()->get('smtp', 'pass');

            return \is_string($decrypted) ? $decrypted : '';
        }

        return '';
    }

    private function mailer(): string
    {
        return (string) ($this->subArray($this->optionArray(self::OPTION), 'mail')['mailer'] ?? '');
    }

    private function host(): string
    {
        return (string) ($this->smtpGroup()['host'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function smtpGroup(): array
    {
        return $this->subArray($this->optionArray(self::OPTION), 'smtp');
    }
}
