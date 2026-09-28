<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Aws\Sns\SnsEndpoint;
use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Verify SNS message against AWS certificate; host-allow-list URL before fetch to prevent SSRF, verify signature with declared digest.
 */
class SnsSignatureVerifier implements WebhookSignatureVerifierInterface
{
    private const CERT_CACHE_TTL = 86400;

    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        return $this->verifyMessage(SnsMessage::fromArray($request->decoded()));
    }

    /**
     * Verify SNS message signature against AWS-hosted certificate; return true for valid known types.
     */
    public function verifyMessage(SnsMessage $sns): bool
    {
        $algo = $this->digestFor($sns->signatureVersion());
        if ($algo === null) {
            return false;
        }

        if (!$this->isTrustedCertUrl($sns->signingCertUrl())) {
            return false;
        }

        $certificate = $this->certificate($sns->signingCertUrl());
        if ($certificate === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return false;
        }

        $signature = base64_decode($sns->signature(), true);
        if ($signature === false || $signature === '') {
            return false;
        }

        return openssl_verify($sns->stringToSign(), $signature, $publicKey, $algo) === 1;
    }

    /**
     * Fetch PEM certificate over HTTPS; overridable for tests; caller ensures URL is host-allow-listed.
     */
    protected function fetchCertificate(string $url): ?string
    {
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);

        return $body !== '' ? $body : null;
    }

    /**
     * Map SignatureVersion to OpenSSL digest algorithm; return null for unknown version.
     */
    private function digestFor(string $version): ?int
    {
        if ($version === '1') {
            return \OPENSSL_ALGO_SHA1;
        }
        if ($version === '2') {
            return \OPENSSL_ALGO_SHA256;
        }

        return null;
    }

    private function isTrustedCertUrl(string $url): bool
    {
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '');

        return SnsEndpoint::isAwsSnsUrl($url) && substr($path, -4) === '.pem';
    }

    /**
     * Fetch signing certificate PEM, cached per URL to avoid re-fetching; null on failure (not cached).
     */
    private function certificate(string $url): ?string
    {
        $cacheKey = 'bit_smtp_sns_cert_' . md5($url);
        $cached   = get_transient($cacheKey);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        $certificate = $this->fetchCertificate($url);
        if ($certificate === null || $certificate === '') {
            return null;
        }

        set_transient($cacheKey, $certificate, self::CERT_CACHE_TTL);

        return $certificate;
    }
}
