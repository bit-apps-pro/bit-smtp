<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;

/**
 * Signs requests with `Authorization: Basic <base64(user:pass)>`, both sides interpolated.
 */
final class BasicAuthStrategy extends AbstractAuthStrategy
{
    public function apply(ApiRequest $request, Connection $connection): void
    {
        $user = $this->interpolate($this->config['userExpr'], $connection);
        $pass = $this->interpolate($this->config['passExpr'], $connection);

        $request->setHeader('Authorization', 'Basic ' . base64_encode($user . ':' . $pass));
    }

    public function type(): string
    {
        return 'basic';
    }
}
