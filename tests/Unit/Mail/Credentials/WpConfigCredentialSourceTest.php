<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Credentials\ConstantReader;
use BitApps\SMTP\Mail\Credentials\WpConfigCredentialSource;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class WpConfigCredentialSourceTest extends BaseUnitTestCase
{
    public function testConstantNameMapsConnectionIdAndKey(): void
    {
        $source = new WpConfigCredentialSource($this->reader([]));

        $this->assertSame('BIT_SMTP_CONN_1_PASSWORD', $source->constantName('conn_1', 'password'));
        $this->assertSame('BIT_SMTP_CONN_1_API_KEY', $source->constantName('conn_1', 'api_key'));
    }

    public function testConstantNameUppercasesAndReplacesNonAlphanumerics(): void
    {
        $source = new WpConfigCredentialSource($this->reader([]));

        // Hyphens, dots and spaces all collapse to underscores; letters uppercase.
        $this->assertSame('BIT_SMTP_CONN_AB_CD_API_KEY', $source->constantName('conn-ab.cd', 'api key'));
    }

    public function testResolveReturnsConstantValueWhenDefinedAndNonEmpty(): void
    {
        $source = new WpConfigCredentialSource($this->reader([
            'BIT_SMTP_CONN_1_PASSWORD' => 'from-wp-config',
        ]));

        $this->assertSame('from-wp-config', $source->resolve('conn_1', 'password'));
    }

    public function testResolveReturnsNullWhenConstantUndefined(): void
    {
        $source = new WpConfigCredentialSource($this->reader([]));

        $this->assertNull($source->resolve('conn_1', 'password'));
    }

    public function testResolveTreatsDefinedButEmptyConstantAsUnset(): void
    {
        // A defined-but-empty constant must fall through to the DB, never lock out the credential.
        $source = new WpConfigCredentialSource($this->reader([
            'BIT_SMTP_CONN_1_PASSWORD' => '',
        ]));

        $this->assertNull($source->resolve('conn_1', 'password'));
    }

    /**
     * A ConstantReader fake backed by an in-memory name => value map; undefined names read as null.
     *
     * @param array<string,string> $constants
     */
    private function reader(array $constants): ConstantReader
    {
        return new class($constants) implements ConstantReader {
            /**
             * @var array<string,string>
             */
            private array $constants;

            /**
             * @param array<string,string> $constants
             */
            public function __construct(array $constants)
            {
                $this->constants = $constants;
            }

            public function read(string $name): ?string
            {
                return $this->constants[$name] ?? null;
            }
        };
    }
}
