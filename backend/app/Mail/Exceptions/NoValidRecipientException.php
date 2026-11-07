<?php

namespace BitApps\SMTP\Mail\Exceptions;

use InvalidArgumentException;

/**
 * The caller named To recipients but none is a valid address, so the mail must fail rather than fall back to native mail().
 */
final class NoValidRecipientException extends InvalidArgumentException
{
    /**
     * @var array<string,mixed>
     */
    private array $fields;

    /**
     * Keep the parsed message fields and name the rejected addresses in a plain-text message, escaped only where it is displayed.
     *
     * @param array<string,mixed> $fields message fields as parsed, with an empty To
     * @param array<int,string>   $given  the To addresses the caller passed
     */
    public function __construct(array $fields, array $given)
    {
        // translators: %s: the recipient addresses that were rejected
        parent::__construct(\sprintf(__('No valid recipient address: %s', 'bit-smtp'), implode(', ', $given)));
        $this->fields = $fields;
    }

    /**
     * The message fields parsed from the wp_mail() arguments, with an empty To.
     *
     * @return array<string,mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
