<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Brevo;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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
                [
                    'key'         => 'api_key', 'label' => __('API key (v3)', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('From SMTP & API > API keys. Use an API key, not the SMTP key.', 'bit-smtp'),
                        'url'       => 'https://developers.brevo.com/docs/getting-started',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
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
            'guide'         => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Transactional mail alongside Brevo newsletters and CRM.', 'bit-smtp'),
                docUrl: 'https://developers.brevo.com/docs/getting-started',
                fromEmailHint: __('Must be a sender or domain verified in Brevo.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_sender',
                        __('Verify a sender or domain', 'bit-smtp'),
                        __('Add the address or domain you send from and confirm it in Brevo.', 'bit-smtp')
                    ),
                    ProviderGuide::step(
                        'api_key',
                        __('Create an API key, not an SMTP key', 'bit-smtp'),
                        __('Brevo shows SMTP keys and API keys side by side; only an API key works here.', 'bit-smtp'),
                        'https://developers.brevo.com/docs/getting-started'
                    ),
                ],
            ),
        ]);
    }
}
