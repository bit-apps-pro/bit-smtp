<?php

namespace BitApps\SMTP\Mail\Providers\Microsoft365;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class Microsoft365Validator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (trim((string) ($settings['client_id'] ?? '')) === '') {
            $errors['client_id'] = __('Application (client) ID is required.', 'bit-smtp');
        }

        if (trim((string) ($credentials['client_secret'] ?? '')) === '') {
            $errors['client_secret'] = __('Client Secret is required.', 'bit-smtp');
        }

        if (trim((string) ($credentials['refresh_token'] ?? '')) === '') {
            $errors['refresh_token'] = __('Microsoft account is not connected.', 'bit-smtp');
        }

        return $errors;
    }
}
