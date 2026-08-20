<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * SparkPost, wired through the descriptor engine (spec §5/§11) except for its body: cc/bcc have no
 * native representation in the transmissions API, so payloadBuilder fully owns request-body
 * construction (see SparkPostPayloadBuilder) instead of the declarative payload map.
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
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true,  'secret' => true,  'placeholder' => '', 'default' => '',   'options' => [], 'dependsOn' => null],
                ['key' => 'region',  'label' => 'Region',  'type' => 'select',   'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'US'],
                    ['value' => 'eu', 'label' => 'EU'],
                ], 'dependsOn' => null],
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
        ]);
    }
}
