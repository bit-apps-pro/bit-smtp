<?php

namespace BitApps\SMTP\Mail\Message;

use BitApps\SMTP\Mail\Exceptions\NoValidRecipientException;
use BitApps\SMTP\Mail\Support\MailAddress;
use InvalidArgumentException;

/**
 * Maintained fork of wp_mail() header/from/content-type resolution; verify on WP bumps.
 */
class MailMessageFactory
{
    private const BOUNDARY_LINE_PATTERN = '/^\s*boundary\s*=/i';

    /**
     * Resolve wp_mail() arguments into a structured MailMessage with headers parsed.
     *
     * @param array<string,mixed> $atts wp_mail() arguments: to, subject, message, headers, attachments
     *
     * @throws NoValidRecipientException when To names addresses but none is valid
     * @throws InvalidArgumentException  when To is empty, which callers leave to native wp_mail()
     */
    public function fromWpMailAtts(array $atts): MailMessage
    {
        $to          = $atts['to']          ?? [];
        $subject     = $atts['subject']     ?? '';
        $message     = $atts['message']     ?? '';
        $headers     = $atts['headers']     ?? '';
        $attachments = $atts['attachments'] ?? [];

        if (!\is_array($to)) {
            $to = explode(',', $to);
        }

        if (!\is_array($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
        }

        $parsed = $this->parseHeaders($headers);

        $fromEmail = $this->resolveFromEmail($parsed['from_email']);
        $fromName  = $this->resolveFromName($parsed['from_name']);

        $headerType   = ContentTypeValue::parse($parsed['content_type'] ?? ContentTypeValue::TEXT_PLAIN);
        $filteredType = $this->filteredContentType($headerType);

        $fields = [
            'to'          => $this->validAddresses($to),
            'cc'          => $this->validAddresses($parsed['cc']),
            'bcc'         => $this->validAddresses($parsed['bcc']),
            'subject'     => $subject,
            'body'        => $message,
            'contentType' => $filteredType->filterValue(),
            'charset'     => $this->resolveCharset($headerType, $filteredType),
            'from'        => $fromEmail,
            'fromName'    => $fromName,
            'replyTo'     => $this->firstReplyTo($parsed['reply_to']),
            'headers'     => $parsed['headers'],
            'attachments' => $attachments,
        ];

        $given = $this->givenAddresses($to);
        if ($fields['to'] === [] && $given !== []) {
            throw new NoValidRecipientException($fields, $given);
        }

        return MailMessage::fromArray($fields);
    }

    /**
     * Whether wp_mail() headers declare a Content-Type of their own.
     *
     * @param string|array<int,string> $headers
     */
    public function declaresContentType($headers): bool
    {
        return $this->parseHeaders($headers)['content_type'] !== null;
    }

    /**
     * Parse headers to extract addresses and the full raw content-type value, mirroring wp_mail() except that non-strings are skipped and continuation lines directly after Content-Type are kept.
     *
     * @param string|array<int,string> $headers
     *
     * @return array{from_email:?string,from_name:?string,content_type:?string,cc:array<int,string>,bcc:array<int,string>,reply_to:array<int,string>,headers:array<string,string>}
     */
    private function parseHeaders($headers): array
    {
        $fromEmail   = null;
        $fromName    = null;
        $contentType = null;
        $cc          = [];
        $bcc         = [];
        $replyTo     = [];
        $extra       = [];

        if (empty($headers)) {
            return $this->headerResult($fromEmail, $fromName, $contentType, $cc, $bcc, $replyTo, $extra);
        }

        if (!\is_array($headers)) {
            $tempHeaders = explode("\n", str_replace("\r\n", "\n", $headers));
        } else {
            $tempHeaders = $headers;
        }

        $followsContentType = false;

        foreach (array_filter((array) $tempHeaders, 'is_string') as $header) {
            if ($this->isContentTypeContinuation($header)) {
                if ($followsContentType) {
                    $contentType .= ';' . $header;
                }

                continue;
            }

            list($name, $content) = explode(':', trim($header), 2);
            $name                 = trim($name);
            $content              = trim($content);
            $followsContentType   = strtolower($name) === 'content-type' && $content !== '';

            switch (strtolower($name)) {
                case 'from':
                    $this->parseFrom($content, $fromEmail, $fromName);

                    break;
                case 'content-type':
                    $contentType = $content !== '' ? $content : $contentType;

                    break;
                case 'cc':
                    $cc = array_merge($cc, explode(',', $content));

                    break;
                case 'bcc':
                    $bcc = array_merge($bcc, explode(',', $content));

                    break;
                case 'reply-to':
                    $replyTo = array_merge($replyTo, explode(',', $content));

                    break;
                default:
                    $extra[trim($name)] = trim($content);

                    break;
            }
        }

        return $this->headerResult($fromEmail, $fromName, $contentType, $cc, $bcc, $replyTo, $extra);
    }

    /**
     * A colon-less line, or a boundary line whose value holds a colon, is a folded continuation rather than a header of its own.
     */
    private function isContentTypeContinuation(string $header): bool
    {
        return strpos($header, ':') === false || preg_match(self::BOUNDARY_LINE_PATTERN, $header) === 1;
    }

    private function parseFrom(string $content, ?string &$fromEmail, ?string &$fromName): void
    {
        $bracketPos = strpos($content, '<');

        if ($bracketPos !== false) {
            if ($bracketPos > 0) {
                $fromName = trim(str_replace('"', '', substr($content, 0, $bracketPos)));
            }

            $fromEmail = trim(str_replace('>', '', substr($content, $bracketPos + 1)));
        } elseif (trim($content) !== '') {
            $fromEmail = trim($content);
        }
    }

    /**
     * Run wp_mail_content_type over the header's type; plugins may return a full value such as "text/html; charset=UTF-8", and returning the same multipart type bare keeps the header's boundary.
     */
    private function filteredContentType(ContentTypeValue $headerType): ContentTypeValue
    {
        $filtered     = apply_filters('wp_mail_content_type', $headerType->filterValue());
        $filteredType = ContentTypeValue::parse(\is_string($filtered) ? $filtered : '');

        return $filteredType->isBareMultipart() && $filteredType->filterValue() === $headerType->mediaType() ? $headerType : $filteredType;
    }

    /**
     * Run the filtered, header or site charset through wp_mail_charset, falling back if invalid; unlike core, multipart keeps a real charset because PHPMailer encodes the subject and From name with it.
     */
    private function resolveCharset(ContentTypeValue $headerType, ContentTypeValue $filteredType): string
    {
        $siteCharset = get_bloginfo('charset');
        $charset     = apply_filters('wp_mail_charset', $filteredType->charset() ?? $headerType->charset() ?? $siteCharset);

        foreach ([$charset, $siteCharset] as $candidate) {
            if (\is_string($candidate) && ContentTypeValue::isValidCharset($candidate)) {
                return $candidate;
            }
        }

        return MailMessage::DEFAULT_CHARSET;
    }

    private function resolveFromEmail(?string $fromEmail): string
    {
        if ($fromEmail === null) {
            $sitename   = wp_parse_url(network_home_url(), PHP_URL_HOST);
            $fromEmail  = 'wordpress@';

            if ($sitename !== null) {
                if (strpos($sitename, 'www.') === 0) {
                    $sitename = substr($sitename, 4);
                }

                $fromEmail .= $sitename;
            }
        }

        return apply_filters('wp_mail_from', $fromEmail);
    }

    private function resolveFromName(?string $fromName): string
    {
        if ($fromName === null) {
            $fromName = 'WordPress';
        }

        return apply_filters('wp_mail_from_name', $fromName);
    }

    /**
     * Trim addresses and drop invalid ones, so one bad address is skipped as in core instead of failing the whole send.
     *
     * @param array<int,string> $addresses
     *
     * @return array<int,string>
     */
    private function validAddresses(array $addresses): array
    {
        return array_values(array_filter(array_map('trim', $addresses), static function ($address) {
            return is_email(MailAddress::split($address)[0]) !== false;
        }));
    }

    /**
     * The non-blank addresses the caller passed, valid or not.
     *
     * @param array<int,string> $addresses
     *
     * @return array<int,string>
     */
    private function givenAddresses(array $addresses): array
    {
        return array_values(array_filter(array_map('trim', $addresses), static fn ($address) => $address !== ''));
    }

    /**
     * MailMessage carries a single reply-to; core collects a list, so take the first.
     *
     * @param array<int,string> $replyTo
     */
    private function firstReplyTo(array $replyTo): ?string
    {
        return $this->validAddresses($replyTo)[0] ?? null;
    }

    /**
     * @param array<int,string>    $cc
     * @param array<int,string>    $bcc
     * @param array<int,string>    $replyTo
     * @param array<string,string> $extra
     *
     * @return array{from_email:?string,from_name:?string,content_type:?string,cc:array<int,string>,bcc:array<int,string>,reply_to:array<int,string>,headers:array<string,string>}
     */
    private function headerResult(?string $fromEmail, ?string $fromName, ?string $contentType, array $cc, array $bcc, array $replyTo, array $extra): array
    {
        return [
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'content_type' => $contentType,
            'cc'           => $cc,
            'bcc'          => $bcc,
            'reply_to'     => $replyTo,
            'headers'      => $extra,
        ];
    }
}
