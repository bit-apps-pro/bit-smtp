<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Cloudflare;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Cloudflare Email Sending provider using its account-scoped structured-builder endpoint.
 */
final class CloudflareProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    public function validator(): ValidatorInterface
    {
        return new CloudflareValidator();
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'cloudflare',
            'label'  => 'Cloudflare',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'account_id', 'label' => 'Account ID', 'type' => 'text', 'required' => true, 'secret' => false, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
                ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'bearer', 'params' => ['credentialKey' => 'api_token']],
            'endpoint' => [
                'host'         => 'api.cloudflare.com',
                'path'         => '/client/v4/accounts/{account_id}/email/sending/send',
                'pathSettings' => ['account_id' => '/^[a-f0-9]{32}$/D'],
            ],
            'encoder' => 'json',
            'payload' => [
                'addresses' => [
                    'from'     => ['source' => 'from', 'shape' => 'address', 'single' => true],
                    'to'       => ['source' => 'to', 'shape' => 'address'],
                    'cc'       => ['source' => 'cc', 'shape' => 'address'],
                    'bcc'      => ['source' => 'bcc', 'shape' => 'address'],
                    'reply_to' => ['source' => 'replyTo', 'shape' => 'address', 'single' => true],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'html', 'text' => 'text'],
                'attachments' => ['key' => 'attachments', 'shape' => 'cloudflare'],
                'headers'     => 'headers',
            ],
            'success'       => [200],
            'errorPaths'    => ['errors.0.message'],
            'messageIdPath' => 'result.message_id',
        ]);
    }
}
