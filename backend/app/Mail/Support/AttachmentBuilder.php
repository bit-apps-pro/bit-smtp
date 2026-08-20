<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads attachment files once and renders each into the JSON attachment shape a given mail provider expects.
 */
final class AttachmentBuilder
{
    public const SHAPE_SENDGRID = 'sendgrid';

    public const SHAPE_POSTMARK = 'postmark';

    public const SHAPE_BREVO = 'brevo';

    public const SHAPE_MAILJET = 'mailjet';

    public const SHAPE_ZEPTO = 'zepto';

    public const SHAPE_RESEND = 'resend';

    public const SHAPE_SPARKPOST = 'sparkpost';

    public const SHAPE_CLOUDFLARE = 'cloudflare';

    private const MIME_FALLBACK = 'application/octet-stream';

    private const KNOWN_SHAPES = [
        self::SHAPE_SENDGRID,
        self::SHAPE_POSTMARK,
        self::SHAPE_BREVO,
        self::SHAPE_MAILJET,
        self::SHAPE_ZEPTO,
        self::SHAPE_RESEND,
        self::SHAPE_SPARKPOST,
        self::SHAPE_CLOUDFLARE,
    ];

    /**
     * @param array<int|string, string> $attachments display filename (or int index) => filesystem path,
     *                                               matching MailMessage::getAttachments()
     *
     * @throws RuntimeException         when a file cannot be read
     * @throws InvalidArgumentException when $shape is not one of the supported provider shapes
     *
     * @return array<int, array<string, string>>
     */
    public function build(array $attachments, string $shape): array
    {
        // Fail-fast: an unknown shape is a caller error regardless of whether the list is empty.
        $this->assertKnownShape($shape);

        $rows = [];

        foreach ($attachments as $filename => $path) {
            $name    = \is_string($filename) ? $filename : basename($path);
            $content = base64_encode($this->readFile($path));
            $mime    = $this->detectMimeType($path);

            $rows[] = $this->toShape($shape, $name, $content, $mime);
        }

        return $rows;
    }

    private function assertKnownShape(string $shape): void
    {
        if (!\in_array($shape, self::KNOWN_SHAPES, true)) {
            throw new InvalidArgumentException(esc_html("Unknown attachment shape: {$shape}"));
        }
    }

    private function toShape(string $shape, string $name, string $content, string $mime): array
    {
        switch ($shape) {
            case self::SHAPE_SENDGRID:
                return ['content' => $content, 'filename' => $name, 'type' => $mime];
            case self::SHAPE_POSTMARK:
                return ['Name' => $name, 'Content' => $content, 'ContentType' => $mime];
            case self::SHAPE_BREVO:
                return ['content' => $content, 'name' => $name];
            case self::SHAPE_MAILJET:
                return ['ContentType' => $mime, 'Filename' => $name, 'Base64Content' => $content];
            case self::SHAPE_ZEPTO:
                return ['content' => $content, 'name' => $name, 'mime_type' => $mime];
            case self::SHAPE_RESEND:
                return ['filename' => $name, 'content' => $content];
            case self::SHAPE_SPARKPOST:
                return ['name' => $name, 'type' => $mime, 'data' => $content];
            case self::SHAPE_CLOUDFLARE:
                return ['content' => $content, 'disposition' => 'attachment', 'filename' => $name, 'type' => $mime];
            default:
                throw new InvalidArgumentException(esc_html("Unknown attachment shape: {$shape}"));
        }
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
        $filetype = wp_check_filetype($path);

        return !empty($filetype['type']) ? $filetype['type'] : self::MIME_FALLBACK;
    }
}
