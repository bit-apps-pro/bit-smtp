<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Webhook\Signatures\SnsSignatureVerifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use OpenSSLAsymmetricKey;

/**
 * Proves the SNS signature check end-to-end with a real RSA keypair: a genuinely signed message
 * verifies, and every tampering / downgrade / spoofed-cert-URL path is rejected.
 *
 * @internal
 *
 * @coversNothing
 */
final class SnsSignatureVerifierTest extends BaseUnitTestCase
{
    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc.pem';

    private OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias(static function (string $url, int $component = -1) {
            return $component === -1 ? parse_url($url) : parse_url($url, $component);
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $this->privateKey   = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $this->publicKeyPem = openssl_pkey_get_details($this->privateKey)['key'];
    }

    public function testAGenuinelySignedV1MessageVerifies(): void
    {
        $message  = $this->signed('1', \OPENSSL_ALGO_SHA1);
        $verifier = $this->verifierWithCert($this->publicKeyPem);

        $this->assertTrue($verifier->verifyMessage($message));
    }

    public function testAGenuinelySignedV2MessageVerifies(): void
    {
        $message  = $this->signed('2', \OPENSSL_ALGO_SHA256);
        $verifier = $this->verifierWithCert($this->publicKeyPem);

        $this->assertTrue($verifier->verifyMessage($message));
    }

    public function testATamperedMessageIsRejected(): void
    {
        // Sign the honest payload, then mutate a signed field — the signature no longer matches.
        $fields                     = $this->fields();
        $signature                  = $this->sign($fields, \OPENSSL_ALGO_SHA1);
        $fields['Message']          = 'attacker rewrote this';
        $fields['Signature']        = $signature;
        $fields['SignatureVersion'] = '1';

        $verifier = $this->verifierWithCert($this->publicKeyPem);

        $this->assertFalse($verifier->verifyMessage(SnsMessage::fromArray($fields)));
    }

    public function testADowngradedSignatureVersionIsRejected(): void
    {
        // Signed with SHA1 but declaring version 2 (SHA256): the digest won't match.
        $fields                     = $this->fields();
        $fields['Signature']        = $this->sign($fields, \OPENSSL_ALGO_SHA1);
        $fields['SignatureVersion'] = '2';

        $verifier = $this->verifierWithCert($this->publicKeyPem);

        $this->assertFalse($verifier->verifyMessage(SnsMessage::fromArray($fields)));
    }

    public function testAnUnknownSignatureVersionIsRejectedWithoutFetchingACert(): void
    {
        $fields                     = $this->fields();
        $fields['SignatureVersion'] = '9';
        $verifier                   = $this->verifierWithCert($this->publicKeyPem);

        $this->assertFalse($verifier->verifyMessage(SnsMessage::fromArray($fields)));
        $this->assertFalse($verifier->fetched, 'an unknown version must short-circuit before any cert fetch');
    }

    public function testASpoofedCertUrlIsRejectedWithoutFetching(): void
    {
        $fields                     = $this->fields();
        $fields['Signature']        = $this->sign($fields, \OPENSSL_ALGO_SHA1);
        $fields['SignatureVersion'] = '1';
        $fields['SigningCertURL']   = 'https://evil-amazonaws.com/cert.pem';

        $verifier = $this->verifierWithCert($this->publicKeyPem);

        $this->assertFalse($verifier->verifyMessage(SnsMessage::fromArray($fields)));
        $this->assertFalse($verifier->fetched, 'a non-SNS cert host must be rejected before any fetch');
    }

    public function testAFailedCertFetchIsRejected(): void
    {
        $message  = $this->signed('1', \OPENSSL_ALGO_SHA1);
        $verifier = $this->verifierWithCert(null);

        $this->assertFalse($verifier->verifyMessage($message));
    }

    /**
     * @return array<string,mixed>
     */
    private function fields(): array
    {
        return [
            'Type'             => SnsMessage::TYPE_NOTIFICATION,
            'MessageId'        => 'm-1',
            'TopicArn'         => 'arn:aws:sns:us-east-1:1:ses',
            'Message'          => '{"notificationType":"Delivery"}',
            'Timestamp'        => '2026-08-26T00:00:00.000Z',
            'SignatureVersion' => '1',
            'SigningCertURL'   => self::CERT_URL,
        ];
    }

    private function signed(string $version, int $algo): SnsMessage
    {
        $fields                     = $this->fields();
        $fields['Signature']        = $this->sign($fields, $algo);
        $fields['SignatureVersion'] = $version;

        return SnsMessage::fromArray($fields);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function sign(array $fields, int $algo): string
    {
        openssl_sign(SnsMessage::fromArray($fields)->stringToSign(), $signature, $this->privateKey, $algo);

        return base64_encode($signature);
    }

    private function verifierWithCert(?string $cert): FixtureSnsSignatureVerifier
    {
        $verifier       = new FixtureSnsSignatureVerifier();
        $verifier->cert = $cert;

        return $verifier;
    }
}

/**
 * @internal
 */
class FixtureSnsSignatureVerifier extends SnsSignatureVerifier
{
    public ?string $cert = null;

    public bool $fetched = false;

    protected function fetchCertificate(string $url): ?string
    {
        $this->fetched = true;

        return $this->cert;
    }
}
