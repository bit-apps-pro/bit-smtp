<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Transport\SmtpTransport;

\defined('ABSPATH') || exit();

/**
 * @deprecated Use \BitApps\SMTP\Mail\Dispatch\WpMailBridge. Kept as a thin alias for backward
 *             compatibility with external references; all logic lives in WpMailBridge.
 */
class SmtpProvider extends WpMailBridge
{
    public function __construct()
    {
        parent::__construct(
            new SmtpTransport(new DatabaseCredentialResolver()),
            new ConnectionResolver()
        );
    }
}
