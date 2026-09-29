<?php

namespace BitApps\SMTP\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

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

    public function fields(): array
    {
        $dependsOnAuth = ['field' => 'auth', 'value' => true];

        return [
            [
                'key'         => 'host', 'label' => 'SMTP Host', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => 'smtp.example.com', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'The outgoing (SMTP) server address from your email provider or host, for example smtp.example.com.',
                ],
            ],
            [
                'key'         => 'port', 'label' => 'SMTP Port', 'type' => 'number', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 587, 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'Use 587 with TLS (STARTTLS) or 465 with SSL (implicit TLS); use 25 only if your provider requires it.',
                    'url'       => 'https://datatracker.ietf.org/doc/html/rfc8314',
                    'linkLabel' => 'About SMTP submission ports',
                ],
            ],
            [
                'key'         => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 'tls',
                'options'     => [
                    ['value' => 'none', 'label' => 'None'],
                    ['value' => 'ssl', 'label' => 'SSL'],
                    ['value' => 'tls', 'label' => 'TLS'],
                ],
                'dependsOn'   => null,
                'help'        => [
                    'text'      => 'Match the port: TLS for 587, SSL for 465. Choose None only for trusted local relays, since credentials are sent unencrypted.',
                    'url'       => 'https://datatracker.ietf.org/doc/html/rfc8314',
                    'linkLabel' => 'About SMTP submission ports',
                ],
            ],
            [
                'key'         => 'auth', 'label' => 'Authentication', 'type' => 'switch', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => true, 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'Turn on if your server requires a login, which most providers do. Turn off only for open or IP-allowlisted relays.',
                ],
            ],
            [
                'key'         => 'username', 'label' => 'Username', 'type' => 'text', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $dependsOnAuth,
                'help'        => [
                    'text'      => 'Usually your full email address or a provider-issued SMTP username.',
                ],
            ],
            [
                'key'         => 'password', 'label' => 'Password', 'type' => 'password', 'required' => false, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $dependsOnAuth,
                'help'        => [
                    'text'      => 'Your mailbox or SMTP password. Providers with 2-step verification, such as Gmail, require an app password instead of your normal password.',
                    'url'       => 'https://support.google.com/accounts/answer/185833',
                    'linkLabel' => 'Create a Google app password',
                ],
            ],
        ];
    }
}
