<?php

namespace BitApps\SMTP\Mail\Aws;

/**
 * AWS Signature Version 4 request signer (pure PHP, no HTTP/WP dependency).
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final class SigV4Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private const TERMINATOR = 'aws4_request';

    /**
     * @param array<string,string> $headers request headers to sign, keyed by name
     *
     * @return array<string,string> the given headers merged with Host, X-Amz-Date,
     *                              X-Amz-Content-Sha256 and Authorization
     */
    public static function sign(
        string $method,
        string $url,
        string $region,
        string $service,
        string $accessKey,
        string $secretKey,
        array $headers,
        string $payload,
        ?string $amzDate = null
    ): array {
        $amzDate   = $amzDate ?? gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);

        $urlParts = parse_url($url);
        $host     = isset($headers['Host']) ? $headers['Host'] : ($urlParts['host'] ?? '');
        $path     = $urlParts['path']  ?? '/';
        $query    = $urlParts['query'] ?? '';

        $contentHash = hash('sha256', $payload);

        $headers['Host']       = $host;
        $headers['X-Amz-Date'] = $amzDate;

        [$canonicalHeaders, $signedHeaders] = self::canonicalizeHeaders($headers);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalUri($path),
            self::canonicalQueryString($query),
            $canonicalHeaders,
            $signedHeaders,
            $contentHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/" . self::TERMINATOR;

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = self::deriveSigningKey($secretKey, $dateStamp, $region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['X-Amz-Content-Sha256'] = $contentHash;
        $headers['Authorization']        = self::ALGORITHM . ' '
            . "Credential={$accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, "
            . "Signature={$signature}";

        return $headers;
    }

    private static function deriveSigningKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', self::TERMINATOR, $kService, true);
    }

    private static function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $segments = array_map(
            static function (string $segment): string {
                return rawurlencode($segment);
            },
            explode('/', $path)
        );

        return implode('/', $segments);
    }

    private static function canonicalQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            $parts   = explode('=', $pair, 2);
            $key     = rawurlencode(urldecode($parts[0]));
            $value   = isset($parts[1]) ? rawurlencode(urldecode($parts[1])) : '';
            $pairs[] = $key . '=' . $value;
        }

        sort($pairs, \SORT_STRING);

        return implode('&', $pairs);
    }

    /**
     * @param array<string,string> $headers
     *
     * @return array{0: string, 1: string} [canonicalHeaders, signedHeaders]
     */
    private static function canonicalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = trim((string) $value);
        }

        ksort($normalized, \SORT_STRING);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $signedHeaders = implode(';', array_keys($normalized));

        return [$canonicalHeaders, $signedHeaders];
    }
}
