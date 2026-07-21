<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Mailjet, wired entirely through the descriptor engine (spec §5/§11).
 */
final class MailjetProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'mailjet',
            'label'  => 'Mailjet',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null],
            ],
            'auth'     => ['type' => 'basic', 'params' => ['userExpr' => '{api_key}', 'passExpr' => '{secret_key}']],
            'endpoint' => ['host' => 'api.mailjet.com', 'path' => '/v3.1/send'],
            'encoder'  => 'json',
            'payload'  => [
                'envelope'  => 'Messages',
                'addresses' => [
                    'From'    => ['source' => 'from',    'shape' => 'object_uc', 'single' => true],
                    'To'      => ['source' => 'to',      'shape' => 'object_uc'],
                    'Cc'      => ['source' => 'cc',      'shape' => 'object_uc'],
                    'Bcc'     => ['source' => 'bcc',     'shape' => 'object_uc'],
                    'ReplyTo' => ['source' => 'replyTo', 'shape' => 'object_uc', 'single' => true],
                ],
                'subject'     => 'Subject',
                'body'        => ['html' => 'HTMLPart', 'text' => 'TextPart'],
                'attachments' => ['key' => 'Attachments', 'shape' => 'mailjet'],
            ],
            'success'    => [200],
            'errorPaths' => ['Messages.0.Errors.0.ErrorMessage', 'Errors.0.ErrorMessage', 'ErrorMessage'],
            // Mailjet reports per-message failures under HTTP 200; its success body carries no
            // Errors/ErrorMessage, so these paths only match a real failure.
            'errorDetectPaths' => ['Messages.0.Errors.0.ErrorMessage', 'Errors.0.ErrorMessage', 'ErrorMessage'],
        ]);
    }
}
