<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Aws;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SigV4SignerTest extends BaseUnitTestCase
{
    /**
     * Pinned against the AWS SigV4 canonical "get-vanilla" test suite vector, proving the
     * canonical-request/string-to-sign/signing-key derivation matches AWS byte-for-byte.
     *
     * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
     */
    public function testGetVanillaVectorMatchesAwsReferenceSignature(): void
    {
        $headers = SigV4Signer::sign(
            'GET',
            'https://example.amazonaws.com/',
            'us-east-1',
            'service',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            ['Host' => 'example.amazonaws.com'],
            '',
            '20150830T123600Z'
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization']
        );
    }

    public function testGetVanillaVectorSetsExpectedAmzDateAndContentHash(): void
    {
        $headers = SigV4Signer::sign(
            'GET',
            'https://example.amazonaws.com/',
            'us-east-1',
            'service',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            ['Host' => 'example.amazonaws.com'],
            '',
            '20150830T123600Z'
        );

        $this->assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        $this->assertSame('example.amazonaws.com', $headers['Host']);
        $this->assertSame(hash('sha256', ''), $headers['X-Amz-Content-Sha256']);
    }

    public function testPostWithBodySignsContentHashAndProducesDeterministicAuthorization(): void
    {
        $payload = '{"Action":"SendEmail"}';

        $headers = SigV4Signer::sign(
            'POST',
            'https://email.us-east-1.amazonaws.com/',
            'us-east-1',
            'ses',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            ['Host' => 'email.us-east-1.amazonaws.com', 'Content-Type' => 'application/json'],
            $payload,
            '20150830T123600Z'
        );

        $this->assertSame(hash('sha256', $payload), $headers['X-Amz-Content-Sha256']);
        $this->assertMatchesRegularExpression(
            '/^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE\/20150830\/us-east-1\/ses\/aws4_request, '
            . 'SignedHeaders=[a-z;-]+, Signature=[0-9a-f]{64}$/',
            $headers['Authorization']
        );

        $repeat = SigV4Signer::sign(
            'POST',
            'https://email.us-east-1.amazonaws.com/',
            'us-east-1',
            'ses',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            ['Host' => 'email.us-east-1.amazonaws.com', 'Content-Type' => 'application/json'],
            $payload,
            '20150830T123600Z'
        );

        $this->assertSame($headers['Authorization'], $repeat['Authorization']);
    }
}
