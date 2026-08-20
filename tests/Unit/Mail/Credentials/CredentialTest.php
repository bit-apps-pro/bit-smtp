<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Credentials\Credential;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class CredentialTest extends BaseUnitTestCase
{
    public function testFromArrayWithValue(): void
    {
        $data = ['source' => 'db', 'value' => 'secret'];
        $credential = Credential::fromArray($data);

        $this->assertSame('db', $credential->getSource());
        $this->assertSame('secret', $credential->getValue());
    }

    public function testFromArrayWithNullValue(): void
    {
        $data = ['source' => 'env', 'value' => null];
        $credential = Credential::fromArray($data);

        $this->assertSame('env', $credential->getSource());
        $this->assertNull($credential->getValue());
    }

    public function testToArrayRoundTrip(): void
    {
        $data = ['source' => 'db', 'value' => 'secret'];
        $credential = Credential::fromArray($data);
        $result = $credential->toArray();

        $this->assertSame($data, $result);
    }
}
