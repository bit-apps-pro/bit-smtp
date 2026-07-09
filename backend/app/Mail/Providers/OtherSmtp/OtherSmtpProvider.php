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

    public function fields(): array
    {
        return [
            ['key' => 'host',           'label' => 'SMTP Host',      'type' => 'text',     'required' => true,  'secret' => false],
            ['key' => 'port',           'label' => 'SMTP Port',      'type' => 'number',   'required' => true,  'secret' => false],
            ['key' => 'encryption',     'label' => 'Encryption',     'type' => 'select',   'required' => true,  'secret' => false],
            ['key' => 'auth',           'label' => 'Authentication', 'type' => 'checkbox', 'required' => false, 'secret' => false],
            ['key' => 'username',       'label' => 'Username',       'type' => 'text',     'required' => false, 'secret' => false],
            ['key' => 'password',       'label' => 'Password',       'type' => 'password', 'required' => false, 'secret' => true],
            ['key' => 'from_email',     'label' => 'From Email',     'type' => 'text',     'required' => false, 'secret' => false],
            ['key' => 'from_name',      'label' => 'From Name',      'type' => 'text',     'required' => false, 'secret' => false],
            ['key' => 'reply_to_email', 'label' => 'Reply-To Email', 'type' => 'text',     'required' => false, 'secret' => false],
        ];
    }
}
