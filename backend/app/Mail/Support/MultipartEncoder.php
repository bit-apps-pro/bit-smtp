<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

use RuntimeException;

/**
 * Encodes a payload as a multipart/form-data body (WordPress's HTTP layer has no native multipart support).
 * Scalar entries become form fields; array entries (displayFilename => path) become repeated file parts.
 */
final class MultipartEncoder implements EncoderInterface
{
    private const MIME_FALLBACK = 'application/octet-stream';

    public function encode(array $payload): array
    {
        $boundary = '----=_BitSMTP_' . bin2hex(random_bytes(16));
        $body     = '';

        foreach ($payload as $key => $value) {
            $body .= \is_array($value)
                ? $this->renderFileParts($boundary, (string) $key, $value)
                : $this->renderFieldPart($boundary, (string) $key, (string) $value);
        }

        $body .= "--{$boundary}--\r\n";

        return ['body' => $body, 'contentType' => "multipart/form-data; boundary={$boundary}"];
    }

    private function renderFieldPart(string $boundary, string $name, string $value): string
    {
        return "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $this->sanitizeHeaderValue($name) . "\"\r\n"
            . "\r\n"
            . "{$value}\r\n";
    }

    /**
     * @param array<int|string, string> $files displayFilename => filesystem path
     */
    private function renderFileParts(string $boundary, string $name, array $files): string
    {
        $parts = '';

        foreach ($files as $filename => $path) {
            $parts .= $this->renderFilePart($boundary, $name, (string) $filename, $path);
        }

        return $parts;
    }

    private function renderFilePart(string $boundary, string $name, string $filename, string $path): string
    {
        $bytes = $this->readFile($path);
        $mime  = $this->detectMimeType($path);

        return "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $this->sanitizeHeaderValue($name) . '"; filename="' . $this->sanitizeHeaderValue($filename) . "\"\r\n"
            . "Content-Type: {$mime}\r\n"
            . "\r\n"
            . "{$bytes}\r\n";
    }

    private function readFile(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException(esc_html("Unable to read attachment file: {$path}"));
        }

        return $bytes;
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return self::MIME_FALLBACK;
        }

        $mime = finfo_file($finfo, $path);

        return $mime !== false && $mime !== '' ? $mime : self::MIME_FALLBACK;
    }

    // CRLF would smuggle a new header/part; a raw quote would break out of the quoted value.
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", '"'], '', $value);
    }
}
