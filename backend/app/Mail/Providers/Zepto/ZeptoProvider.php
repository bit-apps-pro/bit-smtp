<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Zepto;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * ZeptoMail, wired entirely through the descriptor engine (spec §5/§11). First region-mapped
 * provider: the send host is a per-account data center, closed to us/eu/in and defaulting to us.
 */
final class ZeptoProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'zeptomail',
            'label'  => 'ZeptoMail',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key',     'label' => 'Send Mail Token', 'type' => 'password', 'required' => true,  'secret' => true,  'placeholder' => '', 'default' => '',   'options' => [], 'dependsOn' => null],
                ['key' => 'webhook_auth_key', 'label' => 'Webhook Authentication Key', 'type' => 'password', 'required' => false, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
                ['key' => 'data_center', 'label' => 'Data Center',     'type' => 'select',   'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'US'],
                    ['value' => 'eu', 'label' => 'EU'],
                    ['value' => 'in', 'label' => 'India'],
                ], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'api_key', 'params' => ['headerName' => 'Authorization', 'valueTemplate' => 'Zoho-enczapikey {api_key}']],
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.zeptomail.com', 'eu' => 'api.zeptomail.eu', 'in' => 'api.zeptomail.in'],
                'regionSetting' => 'data_center',
                'defaultRegion' => 'us',
                'path'          => '/v1.1/email',
            ],
            'encoder'  => 'json',
            'payload'  => [
                'addresses' => [
                    'from'     => ['source' => 'from',    'shape' => 'address', 'single' => true],
                    'to'       => ['source' => 'to',      'shape' => 'nested'],
                    'cc'       => ['source' => 'cc',      'shape' => 'nested'],
                    'bcc'      => ['source' => 'bcc',     'shape' => 'nested'],
                    'reply_to' => ['source' => 'replyTo', 'shape' => 'address'],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'htmlbody', 'text' => 'textbody'],
                'attachments' => ['key' => 'attachments', 'shape' => 'zepto'],
                'metadata'    => ['key' => 'client_reference', 'value' => TrackingIdStamper::METADATA_KEY],
            ],
            'success'    => [201],
            'errorPaths' => ['error.details.0.message', 'error.message'],
            'tracking'   => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
        ]);
    }
}
