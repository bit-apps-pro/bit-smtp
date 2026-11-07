<?php

namespace BitApps\SMTP\Mail\Message;

use InvalidArgumentException;

class MailMessage
{
    public const DEFAULT_CHARSET = 'UTF-8';

    private array $to;

    private array $cc;

    private array $bcc;

    private string $subject;

    private string $body;

    private ContentTypeValue $contentType;

    private string $charset;

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
        $declaredType        = ContentTypeValue::parse($data['contentType'] ?? ContentTypeValue::TEXT_PLAIN);
        $this->contentType   = self::bodyAwareType($declaredType, $this->body);
        $this->charset       = self::resolveCharset($data['charset'] ?? null, $declaredType);
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
        $this->clickTracking = $data['clickTracking']  ?? null;
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

    /**
     * Value the Content-Type header must carry, minus the charset, which is kept separately.
     */
    public function getContentType(): string
    {
        return $this->contentType->headerValue();
    }

    /**
     * Bare media type, for APIs that reject Content-Type parameters.
     */
    public function getMediaType(): string
    {
        return $this->contentType->mediaType();
    }

    /**
     * Multipart boundary, or null for single-part messages.
     */
    public function getBoundary(): ?string
    {
        return $this->contentType->boundary();
    }

    /**
     * Charset of the subject, From name and body; never empty.
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Whether the body is HTML.
     */
    public function isHtml(): bool
    {
        return $this->contentType->isHtml();
    }

    /**
     * Whether the body is raw multipart MIME.
     */
    public function isMultipart(): bool
    {
        return $this->contentType->isMultipart();
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
            'contentType'   => $this->contentType->headerValue(),
            'charset'       => $this->charset,
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

    /**
     * A multipart type with no usable boundary, as WooCommerce's multipart emails declare, is HTML when the body has real tags and plain text otherwise.
     */
    private static function bodyAwareType(ContentTypeValue $declaredType, string $body): ContentTypeValue
    {
        return $declaredType->isBareMultipart() && HtmlMarkup::isPresentIn($body)
            ? ContentTypeValue::parse(ContentTypeValue::TEXT_HTML)
            : $declaredType;
    }

    /**
     * Use a safe stored charset, else the one in the content type (rows from 2.0.0 keep it there), else UTF-8.
     *
     * @param mixed $charset
     */
    private static function resolveCharset($charset, ContentTypeValue $contentType): string
    {
        if (\is_string($charset) && ContentTypeValue::isValidCharset($charset)) {
            return $charset;
        }

        return $contentType->charset() ?? self::DEFAULT_CHARSET;
    }
}
