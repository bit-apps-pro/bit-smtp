<?php

namespace BitApps\SMTP\Mail\Message;

use InvalidArgumentException;

class MailMessage
{
    private array $to;

    private array $cc;

    private array $bcc;

    private string $subject;

    private string $body;

    private string $contentType;

    private ?string $from;

    private ?string $fromName;

    private ?string $replyTo;

    private array $headers;

    private array $attachments;

    private ?string $templateId;

    private array $templateData;

    private array $tags;

    private array $metadata;

    private ?bool $openTracking;

    private ?bool $clickTracking;

    private function __construct(array $data)
    {
        $this->to            = $data['to'];
        $this->cc            = $data['cc']  ?? [];
        $this->bcc           = $data['bcc'] ?? [];
        $this->subject       = $data['subject'];
        $this->body          = $data['body'];
        $this->contentType   = $data['contentType']    ?? 'text/plain';
        $this->from          = $data['from']           ?? null;
        $this->fromName      = $data['fromName']       ?? null;
        $this->replyTo       = $data['replyTo']        ?? null;
        $this->headers       = $data['headers']        ?? [];
        $this->attachments   = $data['attachments']    ?? [];
        $this->templateId    = $data['templateId']     ?? null;
        $this->templateData  = $data['templateData']   ?? [];
        $this->tags          = $data['tags']           ?? [];
        $this->metadata      = $data['metadata']       ?? [];
        $this->openTracking  = $data['openTracking']   ?? null;
        $this->clickTracking = $data['clickTracking'] ?? null;
    }

    public static function fromArray(array $data): self
    {
        if (empty($data['to'])) {
            throw new InvalidArgumentException('Missing required field: to (must be non-empty)');
        }
        if (!\array_key_exists('subject', $data)) {
            throw new InvalidArgumentException('Missing required field: subject');
        }
        if (!\array_key_exists('body', $data)) {
            throw new InvalidArgumentException('Missing required field: body');
        }

        return new self($data);
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getTemplateId(): ?string
    {
        return $this->templateId;
    }

    public function getTemplateData(): array
    {
        return $this->templateData;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOpenTracking(): ?bool
    {
        return $this->openTracking;
    }

    public function getClickTracking(): ?bool
    {
        return $this->clickTracking;
    }

    public function toArray(): array
    {
        return [
            'to'            => $this->to,
            'cc'            => $this->cc,
            'bcc'           => $this->bcc,
            'subject'       => $this->subject,
            'body'          => $this->body,
            'contentType'   => $this->contentType,
            'from'          => $this->from,
            'fromName'      => $this->fromName,
            'replyTo'       => $this->replyTo,
            'headers'       => $this->headers,
            'attachments'   => $this->attachments,
            'templateId'    => $this->templateId,
            'templateData'  => $this->templateData,
            'tags'          => $this->tags,
            'metadata'      => $this->metadata,
            'openTracking'  => $this->openTracking,
            'clickTracking' => $this->clickTracking,
        ];
    }
}
