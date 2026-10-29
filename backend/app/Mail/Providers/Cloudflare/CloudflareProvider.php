<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Cloudflare;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

/**
 * Cloudflare Email Sending provider using its account-scoped structured-builder endpoint.
 */
final class CloudflareProvider extends DescriptorProvider
{
    public function __construct(ApiClient $client, AuthorizationResolver $resolver)
    {
        parent::__construct(self::descriptor(), $client, $resolver);
    }

    public function validator(): ValidatorInterface
    {
        return new CloudflareValidator();
    }

    private static function descriptor(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray([
            'key'    => 'cloudflare',
            'label'  => 'Cloudflare',
            'kind'   => 'api',
            'fields' => [
                [
                    'key'         => 'account_id', 'label' => __('Account ID', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('The 32-character ID on your Cloudflare account home page.', 'bit-smtp'),
                        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not an asset.
                        'url'       => 'https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
                [
                    'key'         => 'api_token', 'label' => __('API token', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                    'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                    'help'        => [
                        'text'      => __('Create one under My Profile > API Tokens with the Email Sending permission.', 'bit-smtp'),
                        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not an asset.
                        'url'       => 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
                        'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                    ],
                ],
            ],
            'auth'     => ['type' => 'bearer', 'params' => ['credentialKey' => 'api_token']],
            'endpoint' => [
                'host'         => 'api.cloudflare.com',
                'path'         => '/client/v4/accounts/{account_id}/email/sending/send',
                'pathSettings' => ['account_id' => '/^[a-f0-9]{32}$/iD'],
            ],
            'encoder' => 'json',
            'payload' => [
                'addresses' => [
                    'from'     => ['source' => 'from', 'shape' => 'address', 'single' => true],
                    'to'       => ['source' => 'to', 'shape' => 'address'],
                    'cc'       => ['source' => 'cc', 'shape' => 'address'],
                    'bcc'      => ['source' => 'bcc', 'shape' => 'address'],
                    'reply_to' => ['source' => 'replyTo', 'shape' => 'address', 'single' => true],
                ],
                'subject'     => 'subject',
                'body'        => ['html' => 'html', 'text' => 'text'],
                'attachments' => ['key' => 'attachments', 'shape' => 'cloudflare'],
                'headers'     => 'headers',
            ],
            'success'         => [200],
            'semanticSuccess' => [
                'equals'                      => ['success' => true],
                'requiredNonEmptyStringPaths' => ['result.message_id'],
            ],
            'errorPaths'      => ['errors.0.message'],
            'messageIdPath'   => 'result.message_id',
            'guide'           => ProviderGuide::make(
                group: ProviderGuide::GROUP_TRANSACTIONAL,
                bestFor: __('Sites whose domain is already on Cloudflare.', 'bit-smtp'),
                // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not an asset.
                docUrl: 'https://developers.cloudflare.com/email-service/',
                fromEmailHint: __('Must be on a domain onboarded to Cloudflare Email Sending.', 'bit-smtp'),
                prerequisites: [
                    ProviderGuide::step(
                        'onboard_domain',
                        __('Onboard your sending domain', 'bit-smtp'),
                        __('Turn on Email Sending for your domain in the Cloudflare dashboard.', 'bit-smtp'),
                        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not an asset.
                        'https://developers.cloudflare.com/email-service/get-started/send-emails/'
                    ),
                    ProviderGuide::step(
                        'api_token',
                        __('Create an API token', 'bit-smtp'),
                        __('Give it the Email Sending: Edit permission, then copy the token right away.', 'bit-smtp'),
                        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not an asset.
                        'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/'
                    ),
                ],
            ),
        ]);
    }
}
