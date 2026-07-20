<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Mailgun, wired entirely through the descriptor engine (spec §5/§11). Sends multipart/form-data
 * when the message has attachments and application/x-www-form-urlencoded otherwise (FormMultipartEncoder),
 * is Basic-authed with the literal "api" user, and region-maps its host over the {domain} path
 * segment validated by the engine's SSRF-safe resolvePath().
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
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true,  'secret' => true,  'placeholder' => '', 'default' => '',   'options' => [], 'dependsOn' => null],
                ['key' => 'domain',  'label' => 'Domain',  'type' => 'text',     'required' => true,  'secret' => false, 'placeholder' => 'mg.example.com', 'default' => '', 'options' => [], 'dependsOn' => null],
                ['key' => 'region',  'label' => 'Region',  'type' => 'select',   'required' => false, 'secret' => false, 'placeholder' => '', 'default' => 'us', 'options' => [
                    ['value' => 'us', 'label' => 'US'],
                    ['value' => 'eu', 'label' => 'EU'],
                ], 'dependsOn' => null],
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
            ],
            'success'    => [200],
            'errorPaths' => ['message'],
        ]);
    }
}
