<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Postmark;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Postmark, wired entirely through the descriptor engine (spec §5/§11).
 */
final class PostmarkProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'postmark',
            'label'  => 'Postmark',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key', 'label' => 'Server API Token', 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'api_key', 'params' => ['headerName' => 'X-Postmark-Server-Token', 'valueTemplate' => '{api_key}']],
            'endpoint' => ['host' => 'api.postmarkapp.com', 'path' => '/email'],
            'encoder'  => 'json',
            'payload'  => [
                'addresses' => [
                    'From'    => ['source' => 'from',    'shape' => 'rfc822', 'single' => true],
                    'To'      => ['source' => 'to',      'shape' => 'csv'],
                    'Cc'      => ['source' => 'cc',      'shape' => 'csv'],
                    'Bcc'     => ['source' => 'bcc',     'shape' => 'csv'],
                    'ReplyTo' => ['source' => 'replyTo', 'shape' => 'rfc822', 'single' => true],
                ],
                'subject'     => 'Subject',
                'body'        => ['html' => 'HtmlBody', 'text' => 'TextBody'],
                'attachments' => ['key' => 'Attachments', 'shape' => 'postmark'],
            ],
            'success'    => [200],
            'errorPaths' => ['Message'],
        ]);
    }
}
