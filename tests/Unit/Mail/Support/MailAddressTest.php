<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\MailAddress;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class MailAddressTest extends BaseUnitTestCase
{
    /**
     * Address strings with the [email, name] each should split into.
     *
     * @return array<string,array{0:string,1:array{0:string,1:string}}>
     */
    public static function addresses(): array
    {
        return [
            'bare'                 => ['a@x.com', ['a@x.com', '']],
            'bare with spaces'     => ['  a@x.com ', ['a@x.com', '']],
            'named'                => ['Alice <a@x.com>', ['a@x.com', 'Alice']],
            'named with spaces'    => ['  Alice  < a@x.com > ', ['a@x.com', 'Alice']],
            'brackets only'        => ['<a@x.com>', ['a@x.com', '']],
            'unicode name'         => ['Žaneta Krátká <a@x.com>', ['a@x.com', 'Žaneta Krátká']],
            'trailing text'        => ['Alice <a@x.com> extra', ['a@x.com', 'Alice']],
            'two brackets'         => ['<a@x.com> <b@y.com>', ['b@y.com', '<a@x.com>']],
            'empty brackets'       => ['Alice <>', ['Alice <>', '']],
            'empty'                => ['', ['', '']],
        ];
    }

    /**
     * Every supported form splits into the expected email and name.
     *
     * @param array{0:string,1:string} $expected
     */
    #[DataProvider('addresses')]
    public function testSplit(string $address, array $expected): void
    {
        $this->assertSame($expected, MailAddress::split($address));
    }
}
