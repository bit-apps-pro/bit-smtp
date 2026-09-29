<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Mailgun provider wired via descriptor engine with form encoding and region-mapped host.
 */
final class MailgunProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'mailgun',
            'label'  => 'Mailgun',
            'kind'   => 'api',
            'fields' => [
                [
                    'key'         => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true,  'secret' => true,
                    'placeholder' => '', 'default' => '',   'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => 'Use your Mailgun Private API key, found under Settings > API Security in the Mailgun dashboard. It must belong to the same account as the sending domain.',
                        'url'       => 'https://documentation.mailgun.com/docs/mailgun/quickstart/',
                        'linkLabel' => 'View Mailgun quickstart',
                    ],
                ],
                [
                    'key'         => 'webhook_signing_key', 'label' => 'Webhook Signing Key', 'type' => 'password', 'required' => false, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => 'Optional. Copy the HTTP webhook signing key from Settings > API Security so Bit SMTP can verify delivery events sent by Mailgun.',
                    ],
                ],
                [
                    'key'         => 'domain', 'label' => 'Domain',  'type' => 'text',     'required' => true,  'secret' => false,
                    'placeholder' => 'mg.example.com', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => 'The sending domain you added and verified in Mailgun (Sending > Domains), for example mg.example.com. The sandbox domain only delivers to authorized recipients.',
                        'url'       => 'https://documentation.mailgun.com/docs/mailgun/quickstart/',
                        'linkLabel' => 'View Mailgun quickstart',
                    ],
                ],
                ['key' => 'region',  'label' => 'Region',  'type' => 'select',   'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'US'],
                    ['value' => 'eu', 'label' => 'EU'],
                ], 'dependsOn' => null,
                    'help'        => [
                        'text'      => 'Choose the region your domain was created in (shown next to it in Mailgun); sending through the wrong region fails.',
                    ],
                ],
            ],
            'auth'     => ['type' => 'basic', 'params' => ['userExpr' => 'api', 'passExpr' => '{api_key}']],
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.mailgun.net', 'eu' => 'api.eu.mailgun.net'],
                'regionSetting' => 'region',
                'defaultRegion' => 'us',
                'path'          => '/v3/{domain}/messages',
            ],
            'encoder'  => 'multipart',
            'payload'  => [
                'addresses' => [
                    'from'       => ['source' => 'from',    'shape' => 'rfc822', 'single' => true],
                    'to'         => ['source' => 'to',      'shape' => 'rfc822', 'join' => true],
                    'cc'         => ['source' => 'cc',      'shape' => 'rfc822', 'join' => true],
                    'bcc'        => ['source' => 'bcc',     'shape' => 'rfc822', 'join' => true],
                    'h:Reply-To' => ['source' => 'replyTo', 'shape' => 'rfc822', 'single' => true],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'html', 'text' => 'text'],
                'attachments' => ['key' => 'attachment', 'shape' => 'files'],
                'metadata'    => ['key' => 'v:' . TrackingIdStamper::METADATA_KEY, 'value' => TrackingIdStamper::METADATA_KEY],
            ],
            'success'       => [200],
            'errorPaths'    => ['message'],
            'messageIdPath' => 'id',
            'tracking'      => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
        ]);
    }
}
