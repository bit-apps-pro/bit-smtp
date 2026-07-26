<?php

namespace BitApps\SMTP\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Auth\BearerTokenStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Transport\AbstractApiTransport;
use RuntimeException;

class SendGridTransport extends AbstractApiTransport
{
    private const MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'html' => 'text/html',
        'json' => 'application/json',
        'zip'  => 'application/zip',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(ApiClient $client)
    {
        parent::__construct($client);

        $this->useStrategy(new BearerTokenStrategy(['credentialKey' => 'api_key']), new JsonEncoder());
    }

    protected function endpoint(Connection $connection): string
    {
        return 'https://api.sendgrid.com/v3/mail/send';
    }

    /**
     * @return array
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        $body = [
            'personalizations' => [$this->personalization($message)],
            'from'             => $this->fromAddress($message, $connection),
            'subject'          => $message->getSubject(),
            'content'          => [
                [
                    'type'  => $message->getContentType(),
                    'value' => $message->getBody(),
                ],
            ],
        ];

        $replyTo = $this->replyToAddress($message, $connection);
        if ($replyTo !== null) {
            $body['reply_to'] = $replyTo;
        }

        $attachments = $this->buildAttachments($message);
        if (!empty($attachments)) {
            $body['attachments'] = $attachments;
        }

        return $body;
    }

    /**
     * Unused: auth is handled by the BearerTokenStrategy applied in the constructor.
     */
    protected function authHeaders(Connection $connection): array
    {
        return [];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 202;
    }

    protected function toSendResult(ApiResponse $response): SendResult
    {
        return parent::toSendResult($response)
            ->withMessageId($response->getHeader('X-Message-Id'));
    }

    /**
     * SendGrid has no 2xx-with-error-body case: accepted and successful are the same status check.
     *
     * @param array|string $body
     */
    protected function acceptedFrom(int $status, $body): bool
    {
        return $status === 202;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        if (\is_array($body) && isset($body['errors'][0]['message'])) {
            return (string) $body['errors'][0]['message'];
        }

        return 'SendGrid error HTTP ' . $status;
    }

    private function personalization(MailMessage $message): array
    {
        $personalization = ['to' => $this->addresses($message->getTo())];

        $cc = $this->addresses($message->getCc());
        if (!empty($cc)) {
            $personalization['cc'] = $cc;
        }

        $bcc = $this->addresses($message->getBcc());
        if (!empty($bcc)) {
            $personalization['bcc'] = $bcc;
        }

        $customArgs = $this->customArgs($message->getMetadata());
        if (!empty($customArgs)) {
            $personalization['custom_args'] = $customArgs;
        }

        return $personalization;
    }

    private function customArgs(array $metadata): array
    {
        $trackingId = $metadata[TrackingIdStamper::METADATA_KEY] ?? null;
        if (!\is_scalar($trackingId) || (string) $trackingId === '') {
            return [];
        }

        return [TrackingIdStamper::METADATA_KEY => (string) $trackingId];
    }

    private function addresses(array $rawAddresses): array
    {
        return array_map(static function (string $address): array {
            return ['email' => $address];
        }, $rawAddresses);
    }

    private function fromAddress(MailMessage $message, Connection $connection): array
    {
        $messageFrom = $message->getFrom();

        if ($messageFrom !== null && $messageFrom !== '') {
            return ['email' => $messageFrom, 'name' => (string) $message->getFromName()];
        }

        return ['email' => $connection->getFromEmail(), 'name' => $connection->getFromName()];
    }

    private function replyToAddress(MailMessage $message, Connection $connection): ?array
    {
        $email = $message->getReplyTo() ?: $connection->getReplyToEmail();

        return $email ? ['email' => $email] : null;
    }

    /**
     * @throws RuntimeException when an attachment file cannot be read
     */
    private function buildAttachments(MailMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $key => $path) {
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("Unable to read attachment file: {$path}");
            }

            $attachments[] = [
                'content'  => base64_encode($content),
                'filename' => \is_string($key) ? $key : basename($path),
                'type'     => $this->mimeType($path),
            ];
        }

        return $attachments;
    }

    private function mimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }
}
