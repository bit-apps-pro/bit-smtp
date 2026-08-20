<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;

interface TransportInterface
{
    public function send(MailMessage $message, Connection $connection): SendResult;
}
