<?php

namespace BitApps\SMTP\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

class OtherSmtpProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'other_smtp';
    }

    public function label(): string
    {
        return 'Other SMTP';
    }

    public function kind(): string
    {
        return 'smtp';
    }

    public function defaults(): array
    {
        return [
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => true,
        ];
    }

    public function validator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = new OtherSmtpValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'smtp', 'params' => []];
    }

    public function tracking(): array
    {
        return [];
    }

    /**
     * Define SMTP fields; security drives the port, and login fields are required only while auth is on.
     */
    public function fields(): array
    {
        $whenAuthOn = ['field' => 'auth', 'value' => true];

        return [
            [
                'key'         => 'host', 'label' => __('SMTP server', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => 'smtp.example.com', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text' => __('Your email host\'s outgoing mail server, for example smtp.gmail.com.', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'encryption', 'label' => __('Security', 'bit-smtp'), 'type' => 'select', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 'tls',
                'options'     => [
                    ['value' => 'tls', 'label' => __('STARTTLS (port 587, recommended)', 'bit-smtp')],
                    ['value' => 'ssl', 'label' => __('SSL/TLS (port 465)', 'bit-smtp')],
                    ['value' => 'none', 'label' => __('None (port 25, not encrypted)', 'bit-smtp')],
                ],
                'dependsOn' => null,
                'autoSet'   => ['field' => 'port', 'values' => ['tls' => 587, 'ssl' => 465, 'none' => 25]],
                'help'      => [
                    'text'      => __('Encrypts the connection. Pick STARTTLS unless your host tells you otherwise.', 'bit-smtp'),
                    'url'       => 'https://datatracker.ietf.org/doc/html/rfc8314',
                    'linkLabel' => __('Which port should I use?', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'port', 'label' => __('Port', 'bit-smtp'), 'type' => 'number', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 587, 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text' => __('Filled in from Security. Change it only if your host tells you to.', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'auth', 'label' => __('My server requires a login', 'bit-smtp'), 'type' => 'switch', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => true, 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text' => __('Keep this on unless your host lets this server send without a password.', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'username', 'label' => __('Username', 'bit-smtp'), 'type' => 'text', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $whenAuthOn, 'requiredWhen' => $whenAuthOn,
                'help'        => [
                    'text' => __('Usually your full email address.', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'password', 'label' => __('Password', 'bit-smtp'), 'type' => 'password', 'required' => false, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $whenAuthOn, 'requiredWhen' => $whenAuthOn,
                'help'        => [
                    'text'      => __('Use 2-step sign-in, like Gmail? Paste an app password instead.', 'bit-smtp'),
                    'url'       => 'https://support.google.com/accounts/answer/185833',
                    'linkLabel' => __('Create a Google app password', 'bit-smtp'),
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
            bestFor: __('Sending through a mailbox or mail server you already use.', 'bit-smtp'),
            fromEmailHint: __('Use the address you log in with, or one your mail host lets you send as.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'smtp_details',
                    __('Get your SMTP settings', 'bit-smtp'),
                    __('Find the server name, port and security type in your email host\'s setup guide.', 'bit-smtp')
                ),
                ProviderGuide::step(
                    'app_password',
                    __('Create an app password if you use 2-step verification', 'bit-smtp'),
                    __('Hosts such as Google need an app password instead of your normal password when 2-step verification is on.', 'bit-smtp'),
                    'https://support.google.com/accounts/answer/185833'
                ),
            ],
        );
    }
}
