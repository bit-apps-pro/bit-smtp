<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Credentials\CredentialValues;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use stdClass;

/**
 * @internal
 *
 * @coversNothing
 */
class CredentialValuesTest extends BaseUnitTestCase
{
    public function testFlattenUnwrapsStoredSourceValuePairsToScalarStrings(): void
    {
        $this->assertSame(
            ['api_key' => 'key-123', 'secret' => ''],
            CredentialValues::flatten([
                'api_key' => ['source' => 'database', 'value' => 'key-123'],
                'secret'  => ['source' => 'database', 'value' => ''],
            ])
        );
    }

    public function testFlattenDegradesMissingOrMalformedValuesToEmptyString(): void
    {
        $this->assertSame(
            ['no_value' => '', 'null_value' => '', 'nested' => '', 'object' => ''],
            CredentialValues::flatten([
                'no_value'   => ['source' => 'database'],
                'null_value' => ['source' => 'database', 'value' => null],
                'nested'     => ['source' => 'database', 'value' => ['x']],
                'object'     => new stdClass(),
            ])
        );
    }

    public function testFlattenPassesAlreadyScalarCredentialsThrough(): void
    {
        $this->assertSame(['password' => 'plain', 'port' => '25'], CredentialValues::flatten(['password' => 'plain', 'port' => 25]));
    }
}
