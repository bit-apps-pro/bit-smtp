<?php

namespace BitApps\SMTP\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class GmailValidator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (trim((string) ($settings['client_id'] ?? '')) === '') {
            $errors['client_id'] = __('Client ID is required.', 'bit-smtp');
        }

        if (trim((string) ($credentials['client_secret'] ?? '')) === '') {
            $errors['client_secret'] = __('Client Secret is required.', 'bit-smtp');
        }

        if (trim((string) ($credentials['refresh_token'] ?? '')) === '') {
            $errors['refresh_token'] = __('Google account is not connected.', 'bit-smtp');
        }

        return $errors;
    }
}
