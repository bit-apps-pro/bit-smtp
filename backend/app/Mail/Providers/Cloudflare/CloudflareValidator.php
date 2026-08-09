<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Cloudflare;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

final class CloudflareValidator implements ValidatorInterface
{
    private const ACCOUNT_ID_PATTERN = '/^[a-f0-9]{32}$/iD';

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $credentials
     *
     * @return array<string,string>
     */
    public function validate(array $settings, array $credentials): array
    {
        $errors    = [];
        $accountId = trim((string) ($settings['account_id'] ?? ''));
        $apiToken  = trim((string) ($credentials['api_token'] ?? ''));

        if ($accountId === '') {
            $errors['account_id'] = 'Account ID is required.';
        } elseif (preg_match(self::ACCOUNT_ID_PATTERN, $accountId) !== 1) {
            $errors['account_id'] = 'Account ID is invalid.';
        }

        if ($apiToken === '') {
            $errors['api_token'] = 'API Token is required.';
        }

        return $errors;
    }
}
