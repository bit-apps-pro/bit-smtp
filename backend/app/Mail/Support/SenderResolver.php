<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;

/**
 * Resolve from/reply-to address under message-wins, connection-fallback policy.
 */
final class SenderResolver
{
    /**
     * @return string[] empty, or a one-element list: ["Name <addr>"] or ["addr"]
     */
    public function from(MailMessage $message, Connection $connection): array
    {
        $email = $message->getFrom();

        if ($email !== null && $email !== '') {
            return $this->compose($email, (string) $message->getFromName());
        }

        return $this->compose($connection->getFromEmail(), $connection->getFromName());
    }

    /**
     * @return string[] empty, or a one-element list: ["addr"] (reply-to carries no display name)
     */
    public function replyTo(MailMessage $message, Connection $connection): array
    {
        $email = (string) $message->getReplyTo();

        if ($email === '') {
            $email = $connection->getReplyToEmail();
        }

        return $this->compose($email, '');
    }

    /**
     * @return string[]
     */
    private function compose(string $email, string $name): array
    {
        if ($email === '') {
            return [];
        }

        return [$name !== '' ? "{$name} <{$email}>" : $email];
    }
}
