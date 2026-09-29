<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Zepto;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

/**
 * ZeptoMail provider via descriptor engine with region-mapped data-center endpoints.
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
                ['key' => 'data_center', 'label' => __('Account data center', 'bit-smtp'), 'type' => 'select', 'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => __('US', 'bit-smtp')],
                    ['value' => 'eu', 'label' => __('EU', 'bit-smtp')],
                    ['value' => 'in', 'label' => __('India', 'bit-smtp')],
                ], 'dependsOn' => null,
                    'help'        => [
                        'text' => __('Match your ZeptoMail web address: zeptomail.zoho.eu means EU.', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'api_key', 'label' => __('Send Mail token', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('From Mail Agents > SMTP/API. Paste it without the "Zoho-enczapikey" prefix.', 'bit-smtp'),
                        'url'       => 'https://www.zoho.com/zeptomail/help/api/email-sending.html',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'webhook_auth_key', 'label' => __('Webhook authentication key', 'bit-smtp'), 'type' => 'password', 'required' => false, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null, 'section' => 'tracking',
                    'help'        => [
                        'text' => __('Recommended: stops others faking delivery updates. Use the key set on your ZeptoMail webhook.', 'bit-smtp'),
                    ],
                ],
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
            'guide'      => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Transactional-only sending from Zoho.', 'bit-smtp'),
                docUrl: 'https://www.zoho.com/zeptomail/help/api/email-sending.html',
                fromEmailHint: __('Must be on a domain verified in your ZeptoMail Mail Agent.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_domain',
                        __('Add and verify your domain', 'bit-smtp'),
                        __('Add the domain to a Mail Agent and add the DNS records ZeptoMail shows.', 'bit-smtp'),
                        'https://www.zoho.com/zeptomail/help/domains.html'
                    ),
                    ProviderGuide::step(
                        'send_mail_token',
                        __('Copy the Send Mail token', 'bit-smtp'),
                        __('Find it under Mail Agents → your agent → SMTP/API.', 'bit-smtp'),
                        'https://www.zoho.com/zeptomail/help/api/email-sending.html'
                    ),
                ],
            ),
        ]);
    }
}
