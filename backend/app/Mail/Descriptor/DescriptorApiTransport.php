<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Descriptor;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Support\AddressFormatter;
use BitApps\SMTP\Mail\Support\ApiErrorFormatter;
use BitApps\SMTP\Mail\Support\AttachmentBuilder;
use BitApps\SMTP\Mail\Support\EncoderInterface;
use BitApps\SMTP\Mail\Support\SenderResolver;
use BitApps\SMTP\Mail\Transport\AbstractApiTransport;
use InvalidArgumentException;

/**
 * Data-driven API transport: interprets a ProviderDescriptor to build the request body and
 * endpoint, then reuses the parent strategy path to sign (last) and send the request.
 */
final class DescriptorApiTransport extends AbstractApiTransport
{
    /**
     * RFC 1123 hostname (labels of a-z/0-9/hyphen, 1..253 chars total). A path {domain} is a
     * user setting spliced into the request URL, so it must match this before interpolation.
     */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)+$/iD';

    private const DOMAIN_PLACEHOLDER = '{domain}';

    private ProviderDescriptor $descriptor;

    private SenderResolver $senderResolver;

    private AddressFormatter $addressFormatter;

    private AttachmentBuilder $attachmentBuilder;

    private ApiErrorFormatter $errorFormatter;

    public function __construct(
        ApiClient $client,
        ProviderDescriptor $descriptor,
        AuthStrategyInterface $strategy,
        EncoderInterface $encoder
    ) {
        parent::__construct($client);
        parent::useStrategy($strategy, $encoder);

        $this->descriptor        = $descriptor;
        $this->senderResolver    = new SenderResolver();
        $this->addressFormatter  = new AddressFormatter();
        $this->attachmentBuilder = new AttachmentBuilder();
        $this->errorFormatter    = new ApiErrorFormatter();
    }

    protected function endpoint(Connection $connection): string
    {
        $endpoint = $this->descriptor->endpoint();

        return 'https://'
            . $this->resolveHost($endpoint, $connection)
            . $this->resolvePath((string) ($endpoint['path'] ?? ''), $connection);
    }

    /**
     * @return array
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        $payloadBuilder = $this->descriptor->payloadBuilder();
        if ($payloadBuilder !== null) {
            return $payloadBuilder($message, $connection);
        }

        $payload = $this->descriptor->payload();
        $body    = [];

        foreach ($payload['addresses'] ?? [] as $providerKey => $spec) {
            $formatted = $this->formatAddresses($spec, $message, $connection);
            if ($formatted !== null) {
                $body[$providerKey] = $formatted;
            }
        }

        if (isset($payload['subject'])) {
            $body[$payload['subject']] = $message->getSubject();
        }

        $this->applyBody($body, $payload, $message);
        $this->applyAttachments($body, $payload, $message);

        $envelope = $payload['envelope'] ?? null;
        if ($envelope !== null && $envelope !== '') {
            return [$envelope => [$body]];
        }

        return $body;
    }

    protected function authHeaders(Connection $connection): array
    {
        return [];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return \in_array($status, $this->descriptor->success(), true);
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        return $this->errorFormatter->extract($body, $this->descriptor->errorPaths(), $status, $this->descriptor->label());
    }

    /**
     * @return null|array|string null when the resolved address list is empty (key is omitted)
     */
    private function formatAddresses(array $spec, MailMessage $message, Connection $connection)
    {
        $raw = $this->rawAddresses((string) $spec['source'], $message, $connection);
        if (empty($raw)) {
            return;
        }

        $single = !empty($spec['single']);
        if ($single) {
            $raw = \array_slice($raw, 0, 1);
        }

        $formatted = $this->addressFormatter->format($raw, (string) $spec['shape']);

        if ($single && \is_array($formatted)) {
            // null when the formatter dropped a blank-only address; caller then omits the key.
            return $formatted[0] ?? null;
        }

        if (!empty($spec['join']) && \is_array($formatted)) {
            return implode(', ', $formatted);
        }

        return $formatted;
    }

    /**
     * @return string[] raw address strings ("addr" or "Name <addr>")
     */
    private function rawAddresses(string $source, MailMessage $message, Connection $connection): array
    {
        switch ($source) {
            case 'from':
                return $this->senderResolver->from($message, $connection);
            case 'replyTo':
                return $this->senderResolver->replyTo($message, $connection);
            case 'to':
                return $message->getTo();
            case 'cc':
                return $message->getCc();
            case 'bcc':
                return $message->getBcc();
            default:
                throw new InvalidArgumentException("Unknown address source: {$source}");
        }
    }

    private function applyBody(array &$body, array $payload, MailMessage $message): void
    {
        if (!isset($payload['body'])) {
            return;
        }

        $map = $payload['body'];

        // Prefix match: content type arrives as "text/html; charset=UTF-8", not a bare "text/html".
        $key = stripos($message->getContentType(), 'text/html') === 0 ? ($map['html'] ?? null) : ($map['text'] ?? null);

        if ($key !== null) {
            $body[$key] = $message->getBody();
        }
    }

    private function applyAttachments(array &$body, array $payload, MailMessage $message): void
    {
        $attachments = $message->getAttachments();
        if (empty($attachments) || !isset($payload['attachments'])) {
            return;
        }

        $spec  = $payload['attachments'];
        $shape = (string) $spec['shape'];

        $body[$spec['key']] = $shape === 'files'
            ? $attachments
            : $this->attachmentBuilder->build($attachments, $shape);
    }

    private function resolveHost(array $endpoint, Connection $connection): string
    {
        if (!empty($endpoint['host'])) {
            return (string) $endpoint['host'];
        }

        $hostByRegion = $endpoint['hostByRegion'] ?? [];
        $region       = (string) $connection->setting((string) ($endpoint['regionSetting'] ?? ''), '');

        // Closed map: an unknown region is a hard error, never spliced into the host (SSRF guard).
        if (!\array_key_exists($region, $hostByRegion)) {
            throw new InvalidArgumentException('Unknown region for endpoint host: ' . json_encode($region));
        }

        return (string) $hostByRegion[$region];
    }

    private function resolvePath(string $path, Connection $connection): string
    {
        if (strpos($path, self::DOMAIN_PLACEHOLDER) === false) {
            return $path;
        }

        $domain = (string) $connection->setting('domain', '');
        if (preg_match(self::HOSTNAME_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException('Invalid domain for endpoint path');
        }

        return str_replace(self::DOMAIN_PLACEHOLDER, rawurlencode($domain), $path);
    }
}
