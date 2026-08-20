<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Connections;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionCollection;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class ConnectionCollectionTest extends BaseUnitTestCase
{
    private ConnectionCollection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $enabledConn = Connection::fromArray([
            'id' => 'conn-enabled',
            'provider' => 'other_smtp',
            'kind' => 'smtp',
            'name' => 'Enabled Connection',
            'enabled' => true,
            'fromEmail' => 'from@example.com',
            'fromName' => 'From Name',
            'replyToEmail' => 'reply@example.com',
            'settings' => ['host' => 'smtp.example.com'],
            'credentials' => [],
        ]);

        $disabledConn = Connection::fromArray([
            'id' => 'conn-disabled',
            'provider' => 'other_smtp',
            'kind' => 'smtp',
            'name' => 'Disabled Connection',
            'enabled' => false,
            'fromEmail' => 'from@example.com',
            'fromName' => 'From Name',
            'replyToEmail' => 'reply@example.com',
            'settings' => ['host' => 'smtp.example.com'],
            'credentials' => [],
        ]);

        $this->collection = new ConnectionCollection([$enabledConn, $disabledConn]);
    }

    public function testByIdFindsExisting(): void
    {
        $connection = $this->collection->byId('conn-enabled');

        $this->assertNotNull($connection);
        $this->assertSame('conn-enabled', $connection->getId());
    }

    public function testByIdReturnsNullForMissing(): void
    {
        $connection = $this->collection->byId('nonexistent');

        $this->assertNull($connection);
    }

    public function testEnabledFiltersOnlyEnabled(): void
    {
        $filtered = $this->collection->enabled();

        $this->assertCount(1, $filtered);
        $this->assertTrue($filtered->first()->isEnabled());
    }

    public function testFirstReturnsFirst(): void
    {
        $first = $this->collection->first();

        $this->assertNotNull($first);
        $this->assertSame('conn-enabled', $first->getId());
    }

    public function testFirstReturnsNullForEmpty(): void
    {
        $empty = new ConnectionCollection([]);
        $first = $empty->first();

        $this->assertNull($first);
    }

    public function testCountIsCorrect(): void
    {
        $this->assertCount(2, $this->collection);
    }

    public function testIteration(): void
    {
        $connections = [];
        foreach ($this->collection as $connection) {
            $connections[] = $connection;
        }

        $this->assertCount(2, $connections);
        $this->assertContainsOnlyInstancesOf(Connection::class, $connections);
    }

    public function testConstructorThrowsOnNonConnectionElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConnectionCollection(['garbage']);
    }
}
