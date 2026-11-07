<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;

/**
 * Assemble mutable mailData array for logging: base payload + annotations (attempts, sender, routing skips).
 */
final class MailDataBuilder
{
    /**
     * The base log payload for a send, derived from wp_mail's atts and the built message.
     *
     * @param array<string,mixed> $atts
     *
     * @return array<string,mixed>
     */
    public function buildFromAtts(array $atts, MailMessage $message): array
    {
        return $this->buildFromParts($atts, $message->getCc(), $message->getBcc());
    }

    /**
     * The base log payload from wp_mail's atts and already-parsed Cc/Bcc, for a send that never became a message.
     *
     * @param array<string,mixed> $atts
     * @param array<int,string>   $cc
     * @param array<int,string>   $bcc
     *
     * @return array<string,mixed>
     */
    public function buildFromParts(array $atts, array $cc, array $bcc): array
    {
        $to = $atts['to'] ?? [];
        if (!\is_array($to)) {
            $to = explode(',', $to);
        }

        return [
            'to'          => $to,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'subject'     => $atts['subject']     ?? '',
            'message'     => $atts['message']     ?? '',
            'headers'     => $atts['headers']     ?? '',
            'attachments' => $atts['attachments'] ?? [],
        ];
    }

    /**
     * One attempt entry: connection tried and resolution status (sent/accepted/failed).
     *
     * @return array{connection: string, status: string, error: string|null}
     */
    public function attemptEntry(Connection $connection, SendResult $result): array
    {
        if ($result->isOk()) {
            $status = 'sent';
        } elseif ($result->isAccepted()) {
            $status = 'accepted';
        } else {
            $status = 'failed';
        }

        return [
            'connection' => $connection->label(),
            'status'     => $status,
            'error'      => $result->getError(),
        ];
    }

    /**
     * Return $mailData with the send's attempt trail attached.
     *
     * @param array<string,mixed>                                                      $mailData
     * @param array<int,array{connection: string, status: string, error: string|null}> $attempts
     *
     * @return array<string,mixed>
     */
    public function withAttempts(array $mailData, array $attempts): array
    {
        $mailData['attempts'] = $attempts;

        return $mailData;
    }

    /**
     * Return $mailData with the resolved sender attached.
     *
     * @param array<string,mixed> $mailData
     *
     * @return array<string,mixed>
     */
    public function withSender(array $mailData, string $sender): array
    {
        $mailData['from'] = $sender;

        return $mailData;
    }

    /**
     * Return $mailData with the routing-skip trace attached, when there is one.
     *
     * @param array<string,mixed>                                                         $mailData
     * @param array<int,array{connection: string, connection_id: string, reason: string}> $skips
     *
     * @return array<string,mixed>
     */
    public function withRoutingSkips(array $mailData, array $skips): array
    {
        if ($skips !== []) {
            $mailData['routing_skipped'] = $skips;
        }

        return $mailData;
    }
}
