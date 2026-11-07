<?php

namespace BitApps\SMTP\Mail\Message;

/**
 * A parsed Content-Type value whose every re-emitted part is validated, because PHPMailer copies it into raw mail headers unsanitized.
 */
final class ContentTypeValue
{
    public const TEXT_PLAIN = 'text/plain';

    public const TEXT_HTML = 'text/html';

    public const MULTIPART_PREFIX = 'multipart/';

    private const PARAM_CHARSET = 'charset';

    private const PARAM_BOUNDARY = 'boundary';

    private const MEDIA_TYPE_PATTERN = '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D';

    private const CHARSET_PATTERN = '/^[A-Za-z0-9._:+-]+$/D';

    private const PARAM_NAME_PATTERN = '/^[a-z0-9!#$&^_.+-]+$/D';

    private const TOKEN_VALUE_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D';

    private const SAFE_VALUE_PATTERN = '/^[\x20\x21\x23-\x5B\x5D-\x7E]+$/D';

    private const PARAM_PATTERN = '/;\s*([^\s=;]+)\s*=\s*("[^"]*"|[^;]*)/';

    private string $mediaType;

    private ?string $bareMultipartType;

    private ?string $charset;

    /**
     * @var array<string,string>
     */
    private array $headerParams;

    /**
     * @param array<string,string> $params parameters already vetted as safe to re-emit, keyed by lower-cased name
     */
    private function __construct(string $mediaType, array $params)
    {
        $charset      = $params[self::PARAM_CHARSET] ?? '';
        $isWellFormed = preg_match(self::MEDIA_TYPE_PATTERN, $mediaType) === 1;
        $isBare       = $isWellFormed && str_starts_with($mediaType, self::MULTIPART_PREFIX) && !isset($params[self::PARAM_BOUNDARY]);

        $this->charset           = self::isValidCharset($charset) ? $charset : null;
        $this->bareMultipartType = $isBare ? $mediaType : null;
        $this->mediaType         = $isWellFormed && !$isBare ? $mediaType : self::TEXT_PLAIN;

        unset($params[self::PARAM_CHARSET]);
        $this->headerParams = $params;
    }

    /**
     * Parse a raw Content-Type value; a malformed media type, or multipart without a safe boundary, reads as plain text.
     */
    public static function parse(string $value): self
    {
        return new self(strtolower(trim(explode(';', $value, 2)[0])), self::parseParams($value));
    }

    /**
     * Whether a charset name is safe to place in a mail header.
     */
    public static function isValidCharset(string $charset): bool
    {
        return preg_match(self::CHARSET_PATTERN, $charset) === 1;
    }

    /**
     * Lower-cased media type without parameters.
     */
    public function mediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * Charset parameter with quotes removed, or null when absent or unsafe.
     */
    public function charset(): ?string
    {
        return $this->charset;
    }

    /**
     * Boundary parameter with quotes removed, or null when absent or unsafe.
     */
    public function boundary(): ?string
    {
        return $this->headerParams[self::PARAM_BOUNDARY] ?? null;
    }

    /**
     * Any other safe parameter by lower-cased name, or null when absent or unsafe.
     */
    public function param(string $name): ?string
    {
        return $this->headerParams[$name] ?? null;
    }

    /**
     * Whether the media type is HTML.
     */
    public function isHtml(): bool
    {
        return $this->mediaType === self::TEXT_HTML;
    }

    /**
     * Whether the media type is any multipart type.
     */
    public function isMultipart(): bool
    {
        return str_starts_with($this->mediaType, self::MULTIPART_PREFIX);
    }

    /**
     * Whether a well-formed multipart type was declared with no usable boundary, which reads as plain text until something that knows the body decides.
     */
    public function isBareMultipart(): bool
    {
        return $this->bareMultipartType !== null;
    }

    /**
     * Value for wp_mail_content_type and body-aware callers: the header value, except a bare multipart keeps its declared type as core passes it.
     */
    public function filterValue(): string
    {
        return $this->bareMultipartType ?? $this->headerValue();
    }

    /**
     * What the header must carry besides charset, which PHPMailer appends itself: the bare type for HTML and plain text, the type plus its parameters otherwise.
     */
    public function headerValue(): string
    {
        if ($this->isHtml() || $this->mediaType === self::TEXT_PLAIN) {
            return $this->mediaType;
        }

        $parts = [$this->mediaType];
        foreach ($this->headerParams as $name => $value) {
            $parts[] = $name . '=' . self::serializedParamValue($name, $value);
        }

        return implode('; ', $parts);
    }

    /**
     * Extract parameters from a raw value, dropping any whose name or value is unsafe to re-emit.
     *
     * @return array<string,string>
     */
    private static function parseParams(string $value): array
    {
        preg_match_all(self::PARAM_PATTERN, $value, $matches, PREG_SET_ORDER);

        $params = [];
        foreach ($matches as [, $rawName, $rawValue]) {
            $name       = strtolower($rawName);
            $paramValue = self::unquoted(trim($rawValue));

            if (preg_match(self::PARAM_NAME_PATTERN, $name) === 1 && preg_match(self::SAFE_VALUE_PATTERN, $paramValue) === 1) {
                $params[$name] = $paramValue;
            }
        }

        return $params;
    }

    /**
     * Strip one matched pair of surrounding quotes, since a lone apostrophe is a legal boundary character.
     */
    private static function unquoted(string $value): string
    {
        $first = $value[0] ?? '';

        return \strlen($value) >= 2 && ($first === '"' || $first === "'") && substr($value, -1) === $first
            ? substr($value, 1, -1)
            : $value;
    }

    /**
     * Quote a parameter value unless it is a plain token; the boundary is always quoted.
     */
    private static function serializedParamValue(string $name, string $value): string
    {
        if ($name !== self::PARAM_BOUNDARY && preg_match(self::TOKEN_VALUE_PATTERN, $value) === 1) {
            return $value;
        }

        return '"' . $value . '"';
    }
}
