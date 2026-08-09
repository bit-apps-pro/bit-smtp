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
            . $this->resolvePath($endpoint, $connection);
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
        $this->applyMapped($body, $payload, 'metadata', $message->getMetadata());
        $this->applyMapped($body, $payload, 'headers', $message->getHeaders());

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
     * A 2xx status alone isn't enough for providers that opt in: some (e.g. Mailjet) report a
     * per-message failure in the body under a 2xx, so success also requires no error at any of the
     * descriptor's errorDetectPaths — which default to [] (status-only success) for every other one.
     *
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $this->acceptedFrom($status, $body)
            && !$this->errorFormatter->hasError($body, $this->descriptor->errorDetectPaths());
    }

    /**
     * @param array|string $body
     */
    protected function acceptedFrom(int $status, $body): bool
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
     * @param array|string $body
     */
    protected function messageIdFrom(int $status, $body): ?string
    {
        $path = $this->descriptor->messageIdPath();
        if ($path === '' || !\is_array($body)) {
            return null;
        }

        return $this->errorFormatter->resolveScalarPath($body, $path);
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

    /**
     * Emit a message-carried map (metadata, headers) under the provider's declared body key — only
     * when the descriptor declares that key and the message actually carries values.
     *
     * @param array<string,mixed> $values
     */
    private function applyMapped(array &$body, array $payload, string $key, array $values): void
    {
        if (empty($payload[$key]) || $values === []) {
            return;
        }

        if (\is_array($payload[$key])) {
            $target = (string) ($payload[$key]['key'] ?? '');
            $shape  = (string) ($payload[$key]['shape'] ?? '');
            if ($target !== '' && $shape === 'name_value_list') {
                $body[$target] = [];
                foreach ($values as $name => $value) {
                    if (\is_scalar($value)) {
                        $body[$target][] = ['name' => (string) $name, 'value' => (string) $value];
                    }
                }

                if ($body[$target] === []) {
                    unset($body[$target]);
                }

                return;
            }

            $source = (string) ($payload[$key]['value'] ?? '');
            if ($target !== '' && $source !== '' && isset($values[$source])) {
                $body[$target] = $values[$source];
            }

            return;
        }

        $body[$payload[$key]] = $values;
    }

    private function resolveHost(array $endpoint, Connection $connection): string
    {
        if (!empty($endpoint['host'])) {
            return (string) $endpoint['host'];
        }

        $hostByRegion = $endpoint['hostByRegion'] ?? [];
        $region       = (string) $connection->setting((string) ($endpoint['regionSetting'] ?? ''), '');

        if ($region === '' && isset($endpoint['defaultRegion'])) {
            $region = (string) $endpoint['defaultRegion'];
        }

        // Closed map: an unknown region is a hard error, never spliced into the host (SSRF guard).
        if (!\array_key_exists($region, $hostByRegion)) {
            throw new InvalidArgumentException('Unknown region for endpoint host: ' . json_encode($region));
        }

        return (string) $hostByRegion[$region];
    }

    /**
     * @param array<string,mixed> $endpoint
     */
    private function resolvePath(array $endpoint, Connection $connection): string
    {
        $path = (string) ($endpoint['path'] ?? '');

        if (strpos($path, self::DOMAIN_PLACEHOLDER) !== false) {
            $domain = (string) $connection->setting('domain', '');
            if (preg_match(self::HOSTNAME_PATTERN, $domain) !== 1) {
                throw new InvalidArgumentException('Invalid domain for endpoint path');
            }

            $path = str_replace(self::DOMAIN_PLACEHOLDER, rawurlencode($domain), $path);
        }

        foreach (($endpoint['pathSettings'] ?? []) as $key => $pattern) {
            $placeholder = '{' . $key . '}';
            if (strpos($path, $placeholder) === false) {
                continue;
            }
            $setting = $connection->setting((string) $key, '');
            if (!\is_scalar($setting)) {
                throw new InvalidArgumentException('Invalid endpoint path setting: ' . $key);
            }
            $value = (string) $setting;
            if (@preg_match((string) $pattern, $value) !== 1) {
                throw new InvalidArgumentException('Invalid endpoint path setting: ' . $key);
            }
            $path = str_replace($placeholder, rawurlencode($value), $path);
        }
        if (preg_match('/\{[a-z0-9_-]+\}/i', $path) === 1) {
            throw new InvalidArgumentException('Unresolved endpoint path placeholder');
        }

        return $path;
    }
}
