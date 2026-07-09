<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;

/**
 * Base for HTTP API transports; concrete send() plumbing arrives in PR5.
 */
abstract class ApiTransport implements TransportInterface
{
    abstract public function send(MailMessage $message, Connection $connection): SendResult;
}
