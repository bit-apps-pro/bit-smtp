<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

use InvalidArgumentException;

/**
 * Renders a normalized address list into the wire shape a given mail provider expects.
 */
final class AddressFormatter
{
    public const SHAPE_CSV    = 'csv';

    public const SHAPE_OBJECT = 'object';

    public const SHAPE_OBJECT_UC = 'object_uc';

    public const SHAPE_ADDRESS = 'address';

    public const SHAPE_NESTED = 'nested';

    public const SHAPE_RFC822 = 'rfc822';

    /**
     * @param string[] $addresses each entry is either a bare "a@x.com" or "Name <a@x.com>"
     *
     * @return string|array<int, mixed> depending on $shape
     */
    public function format(array $addresses, string $shape)
    {
        $parsed = $this->parseAll($addresses);

        switch ($shape) {
            case self::SHAPE_CSV:
                return $this->toCsv($parsed);
            case self::SHAPE_OBJECT:
                return $this->toObjectList($parsed);
            case self::SHAPE_OBJECT_UC:
                return $this->toObjectUcList($parsed);
            case self::SHAPE_ADDRESS:
                return $this->toAddressList($parsed);
            case self::SHAPE_NESTED:
                return $this->toNestedList($parsed);
            case self::SHAPE_RFC822:
                return $this->toRfc822($parsed);
            default:
                throw new InvalidArgumentException(esc_html("Unknown address shape: {$shape}"));
        }
    }

    /**
     * @param string[] $addresses
     *
     * @return array<int, array{name: string, address: string}>
     */
    private function parseAll(array $addresses): array
    {
        $parsed = array_map([$this, 'parseOne'], $addresses);

        // Blank entries carry no address to render; drop them rather than emit empty recipients.
        return array_values(array_filter($parsed, static function (array $address): bool {
            return $address['address'] !== '';
        }));
    }

    /**
     * @return array{name: string, address: string}
     */
    private function parseOne(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^(.*)<([^<>]+)>$/', $raw, $matches) === 1) {
            return [
                'name'    => trim($matches[1]),
                'address' => trim($matches[2]),
            ];
        }

        return ['name' => '', 'address' => $raw];
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     */
    private function toCsv(array $parsed): string
    {
        return implode(', ', array_column($parsed, 'address'));
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     *
     * @return array<int, array{email: string, name?: string}>
     */
    private function toObjectList(array $parsed): array
    {
        return array_map(static function (array $address): array {
            $entry = ['email' => $address['address']];

            if ($address['name'] !== '') {
                $entry['name'] = $address['name'];
            }

            return $entry;
        }, $parsed);
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     *
     * @return array<int, array{Email: string, Name?: string}>
     */
    private function toObjectUcList(array $parsed): array
    {
        return array_map(static function (array $address): array {
            $entry = ['Email' => $address['address']];

            if ($address['name'] !== '') {
                $entry['Name'] = $address['name'];
            }

            return $entry;
        }, $parsed);
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     *
     * @return array<int, array{address: string, name?: string}>
     */
    private function toAddressList(array $parsed): array
    {
        return array_map(static function (array $address): array {
            $entry = ['address' => $address['address']];

            if ($address['name'] !== '') {
                $entry['name'] = $address['name'];
            }

            return $entry;
        }, $parsed);
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     *
     * @return array<int, array{email_address: array{address: string, name?: string}}>
     */
    private function toNestedList(array $parsed): array
    {
        return array_map(static function (array $address): array {
            $emailAddress = ['address' => $address['address']];

            if ($address['name'] !== '') {
                $emailAddress['name'] = $address['name'];
            }

            return ['email_address' => $emailAddress];
        }, $parsed);
    }

    /**
     * @param array<int, array{name: string, address: string}> $parsed
     *
     * @return string|array<int, string>
     */
    private function toRfc822(array $parsed)
    {
        $formatted = array_map(static function (array $address): string {
            return $address['name'] !== ''
                ? "{$address['name']} <{$address['address']}>"
                : $address['address'];
        }, $parsed);

        return \count($formatted) === 1 ? $formatted[0] : $formatted;
    }
}
