<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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
                ['key' => 'region', 'label' => __('Account region', 'bit-smtp'), 'type' => 'select', 'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => __('US', 'bit-smtp')],
                    ['value' => 'eu', 'label' => __('EU', 'bit-smtp')],
                ], 'dependsOn' => null,
                    'help'        => [
                        'text' => __('Where your Mailgun account lives. A wrong choice looks like a bad API key.', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'domain', 'label' => __('Sending domain', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                    'placeholder' => 'mg.example.com', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Your sending domain exactly as Mailgun shows it, for example mg.example.com.', 'bit-smtp'),
                        'url'       => 'https://documentation.mailgun.com/docs/mailgun/quickstart/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'api_key', 'label' => __('API key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Your private API key from Mailgun\'s API Security page.', 'bit-smtp'),
                        'url'       => 'https://documentation.mailgun.com/docs/mailgun/quickstart/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'webhook_signing_key', 'label' => __('Webhook signing key', 'bit-smtp'), 'type' => 'password', 'required' => false, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null, 'section' => 'tracking',
                    'help'        => [
                        'text' => __('Recommended: stops others faking delivery updates. Find it on Mailgun\'s API Security page.', 'bit-smtp'),
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
            'guide'         => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('High-volume sending with detailed logs.', 'bit-smtp'),
                docUrl: 'https://documentation.mailgun.com/docs/mailgun/user-manual/get-started/',
                fromEmailHint: __('Must be on the sending domain you enter below.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_domain',
                        __('Add and verify a sending domain', 'bit-smtp'),
                        __('Add the DNS records Mailgun shows, in the same region (US or EU) you pick below.', 'bit-smtp'),
                        'https://documentation.mailgun.com/docs/mailgun/user-manual/domains/domains-verify/'
                    ),
                    ProviderGuide::step(
                        'api_key',
                        __('Create an API key', 'bit-smtp'),
                        __('Create one in your Mailgun account settings and copy it right away.', 'bit-smtp'),
                        'https://documentation.mailgun.com/docs/mailgun/user-manual/get-started/'
                    ),
                ],
            ),
        ]);
    }
}
