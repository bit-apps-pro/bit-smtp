<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

/**
 * Mailjet, wired entirely through the descriptor engine (spec §5/§11).
 */
final class MailjetProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    /**
     * Mailjet descriptor; CustomID = tracking ID, errorDetectPaths match real failures only (HTTP 200 carries per-message errors).
     */
    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'mailjet',
            'label'  => 'Mailjet',
            'kind'   => 'api',
            'fields' => [
                [
                    'key'         => 'api_key', 'label' => __('API key (public)', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('From Account settings > API Key Management in Mailjet.', 'bit-smtp'),
                        'url'       => 'https://dev.mailjet.com/email/guides/getting-started/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'secret_key', 'label' => __('Secret key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Shown next to the API key. Both must come from the same pair.', 'bit-smtp'),
                        'url'       => 'https://dev.mailjet.com/email/guides/getting-started/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
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
                'metadata'    => ['key' => 'CustomID', 'value' => TrackingIdStamper::METADATA_KEY],
                'headers'     => 'Headers',
            ],
            'success'          => [200],
            'errorPaths'       => ['Messages.0.Errors.0.ErrorMessage', 'Errors.0.ErrorMessage', 'ErrorMessage'],
            'errorDetectPaths' => ['Messages.0.Errors.0.ErrorMessage', 'Errors.0.ErrorMessage', 'ErrorMessage'],
            'tracking'         => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
            'guide'            => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Transactional mail alongside Mailjet newsletters.', 'bit-smtp'),
                docUrl: 'https://dev.mailjet.com/email/guides/',
                fromEmailHint: __('Must be a validated sender or domain in Mailjet.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_sender',
                        __('Validate a sender or domain', 'bit-smtp'),
                        __('Add the address or domain you send from and confirm it in Mailjet.', 'bit-smtp'),
                        'https://dev.mailjet.com/email/guides/senders-and-domains/'
                    ),
                    ProviderGuide::step(
                        'api_keys',
                        __('Copy your API key and secret key', 'bit-smtp'),
                        __('Find both under Account settings → REST API → API Key Management.', 'bit-smtp'),
                        'https://dev.mailjet.com/email/guides/'
                    ),
                ],
            ),
        ]);
    }
}
