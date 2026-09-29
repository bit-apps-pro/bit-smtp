<?php

namespace BitApps\SMTP\Mail\Providers\Microsoft365;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

class Microsoft365Provider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'microsoft365';
    }

    public function label(): string
    {
        return 'Microsoft 365 / Outlook';
    }

    public function kind(): string
    {
        return 'api';
    }

    public function defaults(): array
    {
        return [];
    }

    public function validator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = new Microsoft365Validator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'oauth2', 'params' => []];
    }

    public function tracking(): array
    {
        return [];
    }

    /**
     * Define fields; tokens are set by OAuth flow, not user-entered.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'client_id', 'label' => __('Application (client) ID', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('From your app\'s Overview page in Microsoft Entra.', 'bit-smtp'),
                    'url'       => 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
                    'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'client_secret', 'label' => __('Client secret value', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Copy the secret\'s Value, not its ID. Sending stops when it expires.', 'bit-smtp'),
                    'url'       => 'https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-credentials#add-a-client-secret',
                    'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'tenant', 'label' => __('Directory (tenant) ID', 'bit-smtp'), 'type' => 'text', 'required' => false, 'secret' => false,
                'placeholder' => 'common', 'default' => 'common', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Work or school account: paste your tenant ID. Personal Outlook.com: keep "common".', 'bit-smtp'),
                    'url'       => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
                    'linkLabel' => __('Open Microsoft Entra', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'oauth', 'label' => __('Microsoft account', 'bit-smtp'), 'type' => 'oauth', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Sign in with the Microsoft mailbox you entered as From Email.', 'bit-smtp'),
                    'url'       => 'https://learn.microsoft.com/en-us/graph/api/user-sendmail',
                    'linkLabel' => __('Which accounts work?', 'bit-smtp'),
                ],
            ],
        ];
    }

    /**
     * Setup guide shown when choosing and configuring this provider.
     */
    public function guide(): array
    {
        return ProviderGuide::make(
            group: ProviderGuide::GROUP_MAILBOX,
            bestFor: __('Low-volume mail from a Microsoft 365 or Outlook.com mailbox you already own.', 'bit-smtp'),
            docUrl: 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
            fromEmailHint: __('Use the mailbox you sign in with, or an address it has Send As rights for.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'register_app',
                    __('Register an app in Microsoft Entra', 'bit-smtp'),
                    __('Add a Web platform redirect URI using the Redirect URI shown below.', 'bit-smtp'),
                    'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app'
                ),
                ProviderGuide::step(
                    'graph_permissions',
                    __('Add Microsoft Graph delegated permissions', 'bit-smtp'),
                    __('Under API permissions, add Mail.Send, offline_access, openid and email.', 'bit-smtp'),
                    'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-configure-app-access-web-apis'
                ),
                ProviderGuide::step(
                    'admin_approval',
                    __('Admin approval (some organizations)', 'bit-smtp'),
                    __('If your organization only lets admins approve apps, ask an admin to approve this app again. It now also asks to see which account signed in.', 'bit-smtp')
                ),
                ProviderGuide::step(
                    'client_secret',
                    __('Create a client secret', 'bit-smtp'),
                    __('Copy the secret\'s Value (not its ID) and note its expiry date, because sending stops then.', 'bit-smtp'),
                    'https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-credentials'
                ),
                ProviderGuide::step(
                    'mailbox',
                    __('Sign in with an account that has a mailbox', 'bit-smtp'),
                    __('The account needs an Exchange Online or Outlook.com mailbox; accounts without one can\'t send.', 'bit-smtp'),
                    'https://learn.microsoft.com/en-us/graph/api/user-sendmail'
                ),
            ],
        );
    }
}
