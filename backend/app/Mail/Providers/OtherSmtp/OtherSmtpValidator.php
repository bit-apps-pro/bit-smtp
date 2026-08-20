<?php

namespace BitApps\SMTP\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class OtherSmtpValidator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (!isset($settings['host']) || trim((string) $settings['host']) === '') {
            $errors['host'] = 'Host is required.';
        }

        $port = $settings['port'] ?? null;

        if (!is_numeric($port)) {
            $errors['port'] = 'Port must be a number.';
        } elseif ((int) $port < 1 || (int) $port > 65535) {
            $errors['port'] = 'Port must be between 1 and 65535.';
        }

        if (!\in_array($settings['encryption'] ?? '', ['none', 'ssl', 'tls'], true)) {
            $errors['encryption'] = 'Encryption must be one of: none, ssl, tls.';
        }

        if (!empty($settings['auth'])) {
            if (trim((string) ($settings['username'] ?? '')) === '') {
                $errors['username'] = 'Username is required when authentication is enabled.';
            }

            if (trim((string) ($credentials['password'] ?? '')) === '') {
                $errors['password'] = 'Password is required when authentication is enabled.';
            }
        }

        return $errors;
    }
}
