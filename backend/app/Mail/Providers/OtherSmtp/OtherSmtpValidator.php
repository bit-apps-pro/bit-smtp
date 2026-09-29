<?php

namespace BitApps\SMTP\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class OtherSmtpValidator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (!isset($settings['host']) || trim((string) $settings['host']) === '') {
            $errors['host'] = __('SMTP server is required.', 'bit-smtp');
        }

        $port = $settings['port'] ?? null;

        if (!is_numeric($port)) {
            $errors['port'] = __('Port must be a number.', 'bit-smtp');
        } elseif ((int) $port < 1 || (int) $port > 65535) {
            $errors['port'] = __('Port must be between 1 and 65535.', 'bit-smtp');
        }

        if (!\in_array($settings['encryption'] ?? '', ['none', 'ssl', 'tls'], true)) {
            $errors['encryption'] = __('Choose a Security option.', 'bit-smtp');
        }

        if (!empty($settings['auth'])) {
            if (trim((string) ($settings['username'] ?? '')) === '') {
                $errors['username'] = __('Username is required when authentication is enabled.', 'bit-smtp');
            }

            if (trim((string) ($credentials['password'] ?? '')) === '') {
                $errors['password'] = __('Password is required when authentication is enabled.', 'bit-smtp');
            }
        }

        return $errors;
    }
}
