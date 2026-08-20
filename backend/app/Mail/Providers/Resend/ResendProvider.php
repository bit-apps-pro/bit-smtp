<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Resend, wired entirely through the descriptor engine (spec §5/§11).
 */
final class ResendProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'resend',
            'label'  => 'Resend',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
                ['key' => 'webhook_signing_secret', 'label' => 'Webhook Signing Secret', 'type' => 'password', 'required' => false, 'secret' => true, 'placeholder' => 'whsec_...', 'default' => '', 'options' => [], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']],
            'endpoint' => ['host' => 'api.resend.com', 'path' => '/emails'],
            'encoder'  => 'json',
            'payload'  => [
                'addresses' => [
                    'from'     => ['source' => 'from',    'shape' => 'rfc822', 'single' => true],
                    'to'       => ['source' => 'to',      'shape' => 'rfc822'],
                    'cc'       => ['source' => 'cc',      'shape' => 'rfc822'],
                    'bcc'      => ['source' => 'bcc',     'shape' => 'rfc822'],
                    'reply_to' => ['source' => 'replyTo', 'shape' => 'rfc822', 'single' => true],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'html', 'text' => 'text'],
                'attachments' => ['key' => 'attachments', 'shape' => 'resend'],
                'metadata'    => ['key' => 'tags', 'shape' => 'name_value_list'],
            ],
            'success'       => [200],
            'errorPaths'    => ['message'],
            'messageIdPath' => 'id',
            'tracking'      => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
        ]);
    }
}
