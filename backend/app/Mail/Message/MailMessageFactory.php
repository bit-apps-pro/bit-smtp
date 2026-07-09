<?php

namespace BitApps\SMTP\Mail\Message;

/**
 * Maintained fork of wp-includes/pluggable.php wp_mail() header/from/content-type resolution
 * (the part a pre_wp_mail short-circuit skips) — re-verify on WP major bumps.
 */
class MailMessageFactory
{
    /**
     * Reproduce wp_mail()'s resolution of $atts into a structured MailMessage: explode the
     * headers, extract From/Content-Type/Cc/Bcc/Reply-To, and apply the same core filters.
     *
     * @param array<string,mixed> $atts wp_mail() arguments: to, subject, message, headers, attachments
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

        $contentType = $parsed['content_type'] !== null ? $parsed['content_type'] : 'text/plain';
        // Core applies this filter here, after defaulting to text/plain.
        $contentType = apply_filters('wp_mail_content_type', $contentType);

        return MailMessage::fromArray([
            'to'          => $this->trimAddresses($to),
            'cc'          => $this->trimAddresses($parsed['cc']),
            'bcc'         => $this->trimAddresses($parsed['bcc']),
            'subject'     => $subject,
            'body'        => $message,
            'contentType' => $contentType,
            'from'        => $fromEmail,
            'fromName'    => $fromName,
            'replyTo'     => $this->firstReplyTo($parsed['reply_to']),
            'headers'     => $parsed['headers'],
            'attachments' => $attachments,
        ]);
    }

    /**
     * Explode a string/array of headers and pull out the addresses and content-type,
     * mirroring wp_mail()'s header loop.
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

        foreach ((array) $tempHeaders as $header) {
            if (strpos($header, ':') === false) {
                continue;
            }

            list($name, $content) = explode(':', trim($header), 2);
            $name                 = trim($name);
            $content              = trim($content);

            switch (strtolower($name)) {
                case 'from':
                    $this->parseFrom($content, $fromEmail, $fromName);

                    break;
                case 'content-type':
                    $this->parseContentType($content, $contentType);

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

    private function parseFrom(string $content, ?string &$fromEmail, ?string &$fromName): void
    {
        $bracketPos = strpos($content, '<');

        if ($bracketPos !== false) {
            if ($bracketPos > 0) {
                $fromName = trim(str_replace('"', '', substr($content, 0, $bracketPos)));
            }

            $fromEmail = trim(str_replace('>', '', substr($content, $bracketPos + 1)));

            // Avoid setting an empty $fromEmail (mirrors core).
        } elseif (trim($content) !== '') {
            $fromEmail = trim($content);
        }
    }

    private function parseContentType(string $content, ?string &$contentType): void
    {
        if (strpos($content, ';') !== false) {
            list($type)  = explode(';', $content);
            $contentType = trim($type);

            // Avoid setting an empty $contentType (mirrors core).
        } elseif (trim($content) !== '') {
            $contentType = trim($content);
        }
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

        // Core applies this filter here.
        return apply_filters('wp_mail_from', $fromEmail);
    }

    private function resolveFromName(?string $fromName): string
    {
        if ($fromName === null) {
            $fromName = 'WordPress';
        }

        // Core applies this filter here.
        return apply_filters('wp_mail_from_name', $fromName);
    }

    /**
     * @param array<int,string> $addresses
     *
     * @return array<int,string>
     */
    private function trimAddresses(array $addresses): array
    {
        return array_values(array_filter(array_map('trim', $addresses), static function ($address) {
            return $address !== '';
        }));
    }

    /**
     * MailMessage carries a single reply-to; core collects a list, so take the first.
     *
     * @param array<int,string> $replyTo
     */
    private function firstReplyTo(array $replyTo): ?string
    {
        $trimmed = $this->trimAddresses($replyTo);

        return $trimmed === [] ? null : $trimmed[0];
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
