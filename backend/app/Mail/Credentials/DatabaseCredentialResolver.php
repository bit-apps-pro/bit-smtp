<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;

class DatabaseCredentialResolver implements CredentialResolverInterface
{
    public function supports(string $source): bool
    {
        return $source === 'database';
    }

    public function resolve(Credential $credential): ?string
    {
        if (!$this->supports($credential->getSource())) {
            return null;
        }

        $value = $credential->getValue();

        // Treat only null/'' as absent — a secret of "0" is valid.
        return ($value === null || $value === '') ? null : $value;
    }
}
