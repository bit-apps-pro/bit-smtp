<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Postmark;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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
                [
                    'key'         => 'api_key', 'label' => __('Server API token', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('From your server\'s API Tokens tab. Use the Server token, not the Account token.', 'bit-smtp'),
                        'url'       => 'https://postmarkapp.com/support/article/1008-what-are-the-account-and-server-api-tokens',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
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
                'metadata'    => 'Metadata',
            ],
            'success'       => [200],
            'errorPaths'    => ['Message'],
            'messageIdPath' => 'MessageID',
            'tracking'      => ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY],
            'guide'         => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Fast, reliable delivery of receipts and password resets.', 'bit-smtp'),
                docUrl: 'https://postmarkapp.com/support/article/1008-what-are-the-account-and-server-api-tokens',
                fromEmailHint: __('Must be a confirmed Sender Signature or on a verified domain in Postmark.', 'bit-smtp'),
                testCaveat: __('Until Postmark approves your account, this test can pass while emails to customers are blocked.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'verify_domain',
                        __('Verify your sending domain', 'bit-smtp'),
                        __('Add the DKIM and Return-Path DNS records Postmark shows you.', 'bit-smtp'),
                        'https://postmarkapp.com/support/article/1046-how-do-i-verify-a-domain'
                    ),
                    ProviderGuide::step(
                        'account_approval',
                        __('Get your account approved', 'bit-smtp'),
                        __('New accounts can only send to your own domains until Postmark approves them.', 'bit-smtp'),
                        'https://postmarkapp.com/support/article/1084-how-does-the-account-approval-process-work'
                    ),
                    ProviderGuide::step(
                        'server_token',
                        __('Copy the Server API token', 'bit-smtp'),
                        __('Find it under Servers → your server → API Tokens; the Account token won\'t work.', 'bit-smtp'),
                        'https://postmarkapp.com/support/article/1008-what-are-the-account-and-server-api-tokens'
                    ),
                ],
            ),
        ]);
    }
}
