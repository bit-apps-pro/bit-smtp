<?php

namespace BitApps\SMTP\Mail\Message;

use ValueError;

/**
 * Reduces a multipart message to its best text part in UTF-8, for API providers that take JSON html/text fields rather than raw MIME.
 *
 * @phpstan-type Part array{type:ContentTypeValue,encoding:string,filename:?string,isAttachment:bool,content:string}
 */
final class MultipartBodyReader
{
    private const MAX_NESTING = 5;

    private const ENCODING_BASE64 = 'base64';

    private const ENCODING_QUOTED_PRINTABLE = 'quoted-printable';

    private const DISPOSITION_ATTACHMENT = 'attachment';

    private const UTF8 = 'UTF-8';

    /**
     * Flatten to the HTML part, else the plain-text part; message is null when no such part is readable, and dropped describes every part left out.
     *
     * @return array{message:?MailMessage,dropped:array<int,string>}
     */
    public function flatten(MailMessage $message): array
    {
        if (!$message->isMultipart()) {
            return ['message' => $message, 'dropped' => []];
        }

        $parts = $this->leafParts($message->getBody(), (string) $message->getBoundary(), self::MAX_NESTING);

        foreach ($this->bodyCandidates($parts) as $index => $part) {
            $body = $this->readableBody($part);
            if ($body !== null) {
                unset($parts[$index]);

                return ['message' => $this->singlePartCopy($message, $part['type'], $body), 'dropped' => $this->descriptions($parts)];
            }
        }

        return ['message' => null, 'dropped' => $this->descriptions($parts)];
    }

    /**
     * Copy of the message holding one UTF-8 part, whatever charset the original declared.
     */
    private function singlePartCopy(MailMessage $message, ContentTypeValue $type, string $body): MailMessage
    {
        return MailMessage::fromArray(array_merge($message->toArray(), [
            'contentType' => $type->mediaType(),
            'charset'     => self::UTF8,
            'body'        => $body,
        ]));
    }

    /**
     * Non-attachment HTML parts then plain-text parts, keeping their indexes into the given list.
     *
     * @param array<int,Part> $parts
     *
     * @return array<int,Part>
     */
    private function bodyCandidates(array $parts): array
    {
        $ofType = static fn (string $mediaType): array => array_filter(
            $parts,
            static fn (array $part): bool => !$part['isAttachment'] && $part['type']->mediaType() === $mediaType
        );

        return $ofType(ContentTypeValue::TEXT_HTML) + $ofType(ContentTypeValue::TEXT_PLAIN);
    }

    /**
     * Describe parts by type and filename only, never their contents.
     *
     * @param array<int,Part> $parts
     *
     * @return array<int,string>
     */
    private function descriptions(array $parts): array
    {
        return array_values(array_map(static function (array $part): string {
            $mediaType = $part['type']->mediaType();

            return $part['filename'] !== null ? $mediaType . ' (' . $part['filename'] . ')' : $mediaType;
        }, $parts));
    }

    /**
     * Collect the parts of a multipart body, descending into nested multiparts; one past the depth limit stays as an unread part.
     *
     * @return array<int,Part>
     */
    private function leafParts(string $body, string $boundary, int $nestingLeft): array
    {
        $leaves = [];

        foreach ($this->rawParts($body, $boundary) as $rawPart) {
            $part = $this->parsePart($rawPart);
            if ($part === null) {
                continue;
            }

            if ($part['type']->isMultipart() && $nestingLeft > 0) {
                $leaves = array_merge($leaves, $this->leafParts($part['content'], (string) $part['type']->boundary(), $nestingLeft - 1));
            } else {
                $leaves[] = $part;
            }
        }

        return $leaves;
    }

    /**
     * Split a multipart body on its delimiter lines, discarding the preamble and epilogue.
     *
     * @return array<int,string>
     */
    private function rawParts(string $body, string $boundary): array
    {
        $delimiter = '(?:\A|\r?\n)--' . preg_quote($boundary, '/');

        $withoutEpilogue = preg_split('/' . $delimiter . '--[ \t]*(?:\r?\n|\z)/', $body, 2);
        $segments        = preg_split('/' . $delimiter . '[ \t]*(?=\r?\n|\z)/', $withoutEpilogue === false ? $body : $withoutEpilogue[0]);

        return $segments === false ? [] : \array_slice($segments, 1);
    }

    /**
     * Separate a raw part into its parsed headers and content; null when it has no header/body separator.
     *
     * @return null|Part
     */
    private function parsePart(string $rawPart): ?array
    {
        $sections = preg_split('/\r?\n\r?\n/', $rawPart, 2);
        if ($sections === false || \count($sections) !== 2) {
            return null;
        }

        $headers = (string) preg_replace('/\r?\n[ \t]+/', ' ', $sections[0]);
        $content = $sections[1];

        $type        = ContentTypeValue::parse($this->headerValue($headers, 'Content-Type'));
        $disposition = $this->headerValue($headers, 'Content-Disposition');

        return [
            'type'         => $type,
            'encoding'     => strtolower($this->headerValue($headers, 'Content-Transfer-Encoding')),
            'filename'     => ContentTypeValue::parse($disposition)->param('filename') ?? $type->param('name'),
            'isAttachment' => stripos($disposition, self::DISPOSITION_ATTACHMENT) === 0,
            'content'      => $content,
        ];
    }

    /**
     * Read one header from a part's already unfolded header block.
     */
    private function headerValue(string $headers, string $name): string
    {
        return preg_match('/^' . $name . ':[ \t]*(.*?)[ \t\r]*$/mi', $headers, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Decode a part to UTF-8: bytes already valid as UTF-8 are kept despite any label, others convert from the labelled charset; null when that is impossible or nothing but whitespace remains.
     *
     * @param Part $part
     */
    private function readableBody(array $part): ?string
    {
        $content = $this->decoded($part);
        $body    = preg_match('//u', $content) === 1 ? $content : $this->convertedToUtf8($content, $part['type']->charset());

        return $body !== null && trim($body) !== '' ? $body : null;
    }

    /**
     * Undo the part's Content-Transfer-Encoding.
     *
     * @param Part $part
     */
    private function decoded(array $part): string
    {
        return match ($part['encoding']) {
            self::ENCODING_BASE64           => (string) base64_decode($part['content']),
            self::ENCODING_QUOTED_PRINTABLE => quoted_printable_decode($part['content']),
            default                         => $part['content'],
        };
    }

    /**
     * Convert from a labelled charset to UTF-8; null with no label, no mbstring, or a charset mbstring does not know.
     */
    private function convertedToUtf8(string $content, ?string $from): ?string
    {
        if ($from === null || !\function_exists('mb_convert_encoding')) {
            return null;
        }

        try {
            $converted = mb_convert_encoding($content, self::UTF8, $from);
        } catch (ValueError) {
            return null;
        }

        return \is_string($converted) ? $converted : null;
    }
}
