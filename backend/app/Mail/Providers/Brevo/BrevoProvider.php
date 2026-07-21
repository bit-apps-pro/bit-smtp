<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Brevo;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Brevo, wired entirely through the descriptor engine (spec §5/§11).
 */
final class BrevoProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'brevo',
            'label'  => 'Brevo',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'api_key', 'params' => ['headerName' => 'api-key', 'valueTemplate' => '{api_key}']],
            'endpoint' => ['host' => 'api.brevo.com', 'path' => '/v3/smtp/email'],
            'encoder'  => 'json',
            'payload'  => [
                'addresses' => [
                    'sender'  => ['source' => 'from',    'shape' => 'object', 'single' => true],
                    'to'      => ['source' => 'to',      'shape' => 'object'],
                    'cc'      => ['source' => 'cc',      'shape' => 'object'],
                    'bcc'     => ['source' => 'bcc',     'shape' => 'object'],
                    'replyTo' => ['source' => 'replyTo', 'shape' => 'object', 'single' => true],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'htmlContent', 'text' => 'textContent'],
                'attachments' => ['key' => 'attachment', 'shape' => 'brevo'],
                'headers'     => 'headers',
            ],
            'success'       => [201],
            'errorPaths'    => ['message'],
            'messageIdPath' => 'messageId',
            'tracking'      => ['channel' => 'header', 'key' => 'X-Mailin-custom'],
        ]);
    }
}
