<?php

namespace BitApps\SMTP\Mail\Providers\PhpSendmail;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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

    /**
     * Setup guide shown when choosing and configuring this provider.
     */
    public function guide(): array
    {
        return ProviderGuide::make(
            group: ProviderGuide::GROUP_SERVER,
            bestFor: __('A last-resort backup when no other option is available.', 'bit-smtp'),
            docUrl: 'https://www.php.net/manual/en/function.mail.php',
            fromEmailHint: __('Use an address on this site\'s own domain; other domains are often marked as spam.', 'bit-smtp'),
            testCaveat: __('A passing test only means your server accepted the email, so check the inbox too.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'host_allows_mail',
                    __('Confirm your host allows PHP mail', 'bit-smtp'),
                    __('Many hosts block it, so ask your host first or pick an email service instead.', 'bit-smtp'),
                    'https://www.php.net/manual/en/function.mail.php'
                ),
            ],
        );
    }
}
