<?php

namespace BitApps\SMTP\Mail\Support;

/**
 * Splits a "Name <email>" string, so recipient validation and sending agree on the address; MailMessageFactory::parseFrom reads the From header separately.
 */
final class MailAddress
{
    private const NAMED_ADDRESS_PATTERN = '/(.*)<([^<>]+)>/';

    /**
     * Split a bare or "Name <email>" string into [email, name], in the argument order PHPMailer's address methods take.
     *
     * @return array{0:string,1:string}
     */
    public static function split(string $address): array
    {
        if (preg_match(self::NAMED_ADDRESS_PATTERN, $address, $matches) === 1) {
            return [trim($matches[2]), trim($matches[1])];
        }

        return [trim($address), ''];
    }
}
