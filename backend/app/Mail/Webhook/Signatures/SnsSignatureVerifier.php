<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Aws\Sns\SnsEndpoint;
use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Verifies an inbound Amazon SNS message (Amazon SES delivery notifications) against a signing
 * certificate fetched from AWS. The certificate URL is host-allow-listed BEFORE any fetch so a
 * spoofed SigningCertURL can never point us at an attacker host; the signature is checked with the
 * digest the message's SignatureVersion declares (SHA1 for v1, SHA256 for v2).
 */
class SnsSignatureVerifier implements WebhookSignatureVerifierInterface
{
    private const CERT_CACHE_TTL = 86400;

    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        return $this->verifyMessage(SnsMessage::fromArray($request->decoded()));
    }

    /**
     * True only when $sns is a known SNS type whose signature validates against an AWS-hosted cert.
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
     * Fetch the PEM over HTTPS. Overridable so tests supply a fixture cert without a network call; the
     * URL is already host-allow-listed by the caller.
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
     * The OpenSSL digest for a declared SignatureVersion, or null for an unknown version (rejected).
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
     * The signing certificate PEM, cached per URL (AWS rotates infrequently) so repeated events don't
     * re-fetch it. A fetch failure returns null and is never cached.
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
