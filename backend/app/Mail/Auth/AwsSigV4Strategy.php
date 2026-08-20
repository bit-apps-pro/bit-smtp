<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;
use BitApps\SMTP\Mail\Support\ApiRequest;

/**
 * Signs the built request with AWS Signature Version 4, covering the exact body bytes and
 * headers the transport already assembled.
 */
final class AwsSigV4Strategy extends AbstractAuthStrategy
{
    /**
     * AWS region shape, allowing GovCloud/ISO's extra segment (e.g. us-east-1, us-gov-east-1);
     * the region is interpolated into the signed request host, so anything outside this charset
     * must never reach the signer. The `D` modifier makes `$` match only the true end of string,
     * rejecting a trailing newline.
     */
    private const REGION_PATTERN = '/^[a-z]{2}(-[a-z]+)+-\d+$/D';

    private SigV4Signer $signer;

    private string $service;

    public function __construct(SigV4Signer $signer, string $service)
    {
        parent::__construct([]);
        $this->signer  = $signer;
        $this->service = $service;
    }

    public function apply(ApiRequest $request, Connection $connection): void
    {
        $region = (string) $connection->setting('region', '');

        if (!preg_match(self::REGION_PATTERN, $region)) {
            throw AuthConfigException::invalidRegion(esc_html($region));
        }

        $accessKey = (string) $connection->setting('access_key', '');
        $secretKey = $this->secret($connection, 'secret_key');

        $baseHeaders = [
            'Host'                 => (string) wp_parse_url($request->url, \PHP_URL_HOST),
            'Content-Type'         => $request->contentType,
            'X-Amz-Content-Sha256' => hash('sha256', $request->body),
        ];

        $signedHeaders = $this->signer->sign(
            'POST',
            $request->url,
            $region,
            $this->service,
            $accessKey,
            $secretKey,
            $baseHeaders,
            $request->body
        );

        foreach ($signedHeaders as $name => $value) {
            $request->setHeader($name, $value);
        }
    }

    public function type(): string
    {
        return 'aws_sigv4';
    }
}
