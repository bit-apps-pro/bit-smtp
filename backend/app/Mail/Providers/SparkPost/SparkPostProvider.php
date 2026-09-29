<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

/**
 * SparkPost provider via descriptor engine with custom payload builder for cc/bcc handling.
 */
final class SparkPostProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'sparkpost',
            'label'  => 'SparkPost',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'region', 'label' => __('Account region', 'bit-smtp'), 'type' => 'select', 'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => __('US', 'bit-smtp')],
                    ['value' => 'eu', 'label' => __('EU', 'bit-smtp')],
                ], 'dependsOn' => null,
                    'help'        => [
                        'text' => __('Where your SparkPost account lives. A wrong choice looks like a bad API key.', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'api_key', 'label' => __('API key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Create one under API Keys and allow it to send email (Transmissions).', 'bit-smtp'),
                        'url'       => 'https://support.sparkpost.com/docs/getting-started/create-api-keys',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
            ],
            'auth'     => ['type' => 'api_key', 'params' => ['headerName' => 'Authorization', 'valueTemplate' => '{api_key}']],
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.sparkpost.com', 'eu' => 'api.eu.sparkpost.com'],
                'regionSetting' => 'region',
                'defaultRegion' => 'us',
                'path'          => '/api/v1/transmissions',
            ],
            'encoder'          => 'json',
            'success'          => [200],
            'errorPaths'       => ['errors.0.message'],
            'errorDetectPaths' => ['errors.0.message'],
            'messageIdPath'    => 'results.id',
            'payloadBuilder'   => [new SparkPostPayloadBuilder(), 'build'],
            'tracking'         => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
            'guide'            => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('High-volume sending with detailed analytics.', 'bit-smtp'),
                docUrl: 'https://support.sparkpost.com/docs/getting-started/create-api-keys',
                fromEmailHint: __('Must be on a verified SparkPost sending domain.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'sending_domain',
                        __('Add and verify a sending domain', 'bit-smtp'),
                        __('Add the DNS records SparkPost shows for your domain.', 'bit-smtp'),
                        'https://support.sparkpost.com/docs/getting-started/setting-up-domains'
                    ),
                    ProviderGuide::step(
                        'api_key',
                        __('Create an API key', 'bit-smtp'),
                        __('Allow it to send email, and to manage webhooks if you want delivery tracking.', 'bit-smtp'),
                        'https://support.sparkpost.com/docs/getting-started/create-api-keys'
                    ),
                ],
            ),
        ]);
    }
}
