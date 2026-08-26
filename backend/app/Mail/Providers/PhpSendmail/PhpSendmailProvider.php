<?php

namespace BitApps\SMTP\Mail\Providers\PhpSendmail;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class PhpSendmailProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'php_sendmail';
    }

    public function label(): string
    {
        return 'PHP Sendmail';
    }

    public function kind(): string
    {
        return 'local';
    }

    public function fields(): array
    {
        return [];
    }

    public function defaults(): array
    {
        return [];
    }

    public function validator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = new PhpSendmailValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'none', 'params' => []];
    }

    public function tracking(): array
    {
        return [];
    }
}
