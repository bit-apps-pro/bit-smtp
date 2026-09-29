<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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
                [
                    'key'         => 'api_key', 'label' => __('API key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Create one under API Keys. Full access lets delivery tracking set itself up.', 'bit-smtp'),
                        'url'       => 'https://resend.com/docs/dashboard/api-keys/introduction',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'webhook_signing_secret', 'label' => __('Webhook signing secret', 'bit-smtp'), 'type' => 'password', 'required' => false, 'secret' => true,
                    'placeholder' => 'whsec_...', 'default' => '', 'options' => [], 'dependsOn' => null, 'section' => 'tracking',
                    'help'        => [
                        'text'      => __('Filled in for you. Only needed if you created the Resend webhook yourself.', 'bit-smtp'),
                        'url'       => 'https://resend.com/docs/dashboard/webhooks/verify-webhooks-requests',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
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
            'guide'         => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Developer-friendly sending with a simple setup.', 'bit-smtp'),
                docUrl: 'https://resend.com/docs/dashboard/api-keys/introduction',
                fromEmailHint: __('Must be on a domain verified in Resend.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_domain',
                        __('Verify your sending domain', 'bit-smtp'),
                        __('Add the DNS records Resend shows you and wait until the domain shows as verified.', 'bit-smtp'),
                        'https://resend.com/docs/dashboard/domains/introduction'
                    ),
                    ProviderGuide::step(
                        'api_key',
                        __('Create an API key', 'bit-smtp'),
                        __('Create one under API Keys in Resend and copy it right away.', 'bit-smtp'),
                        'https://resend.com/docs/dashboard/api-keys/introduction'
                    ),
                ],
            ),
        ]);
    }
}
