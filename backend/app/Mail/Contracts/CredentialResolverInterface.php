<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Credentials\Credential;

interface CredentialResolverInterface
{
    public function supports(string $source): bool;

    public function resolve(Credential $credential): ?string;
}
