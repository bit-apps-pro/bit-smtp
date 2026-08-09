<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Abilities;

use BitApps\SMTP\Mail\Abilities\AbilitiesProvider;
use BitApps\SMTP\Mail\Abilities\AbilitySchemas;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class AbilitySchemasTest extends BaseUnitTestCase
{
    public function testAnalyticsInputSchemasAreClosedAndConstrainAllPublicFilters(): void
    {
        foreach ([
            AbilitySchemas::overviewInput(),
            AbilitySchemas::pluginInput(),
            AbilitySchemas::deliverabilityInput(),
            AbilitySchemas::anomaliesInput(),
        ] as $schema) {
            self::assertSame('object', $schema['type']);
            self::assertFalse($schema['additionalProperties']);
            self::assertSame([], $schema['default']);
            self::assertSame('date-time', $schema['properties']['start']['format']);
            self::assertSame('date-time', $schema['properties']['end']['format']);
            self::assertSame(['hour', 'day', 'week'], $schema['properties']['bucket']['enum']);
            self::assertSame('^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$', $schema['properties']['connection_id']['pattern']);
        }

        $plugin = AbilitySchemas::pluginInput();
        self::assertSame(['plugin'], $plugin['required']);
        self::assertSame(1, $plugin['properties']['plugin']['minLength']);
        self::assertSame('^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)?$', $plugin['properties']['plugin']['pattern']);
    }

    public function testRoutingInputRequiresExactlyOneStrictActualOrSimulationMode(): void
    {
        $schema = AbilitySchemas::routingInput();

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertCount(2, $schema['oneOf']);
        foreach ($schema['oneOf'] as $mode) {
            self::assertSame('object', $mode['type']);
            self::assertFalse($mode['additionalProperties']);
        }

        self::assertSame(['log_id'], $schema['oneOf'][0]['required']);
        self::assertSame(1, $schema['oneOf'][0]['properties']['log_id']['minimum']);
        self::assertSame(['to_domains'], $schema['oneOf'][1]['required']);
        self::assertSame(1, $schema['oneOf'][1]['properties']['to_domains']['minItems']);
        self::assertSame(50, $schema['oneOf'][1]['properties']['to_domains']['maxItems']);
        self::assertSame(
            '^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$',
            $schema['oneOf'][1]['properties']['to_domains']['items']['pattern']
        );
    }

    public function testOutputSchemasAreCompleteStrictObjectsThatOnlyDescribeAggregateFields(): void
    {
        foreach ([
            AbilitySchemas::overviewOutput(),
            AbilitySchemas::pluginOutput(),
            AbilitySchemas::deliverabilityOutput(),
            AbilitySchemas::routingOutput(),
            AbilitySchemas::anomaliesOutput(),
        ] as $schema) {
            $this->assertObjectsAreStrict($schema);
        }

        $overview = AbilitySchemas::overviewOutput();
        self::assertArrayHasKey('recipients', $overview['properties']);
        self::assertArrayNotHasKey('to_addr', $overview['properties']);
        self::assertArrayNotHasKey('subject', $overview['properties']);
        self::assertArrayNotHasKey('debug_info', $overview['properties']);

        $plugin = AbilitySchemas::pluginOutput();
        self::assertArrayHasKey('subject_patterns', $plugin['properties']);
        self::assertArrayNotHasKey('recipients', $plugin['properties']['subject_patterns']['items']['properties']);

        $routing = AbilitySchemas::routingOutput();
        self::assertSame(
            ['equals', 'contains', 'domain', 'matches'],
            $routing['oneOf'][1]['properties']['rules']['items']['properties']['conditions']['items']['properties']['operator']['enum']
        );
    }

    public function testDoesNotAddAnyAbilitiesHooksWhenCoreDoesNotProvideTheApi(): void
    {
        Functions\expect('add_action')->never();

        (new AbilitiesProvider())->register();
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function assertObjectsAreStrict(array $schema): void
    {
        if (($schema['type'] ?? null) === 'object') {
            self::assertFalse($schema['additionalProperties'] ?? null);
            self::assertArrayHasKey('properties', $schema);
        }

        foreach (($schema['properties'] ?? []) as $property) {
            self::assertIsArray($property);
            $this->assertObjectsAreStrict($property);
        }

        if (isset($schema['items']) && \is_array($schema['items'])) {
            $this->assertObjectsAreStrict($schema['items']);
        }

        foreach (($schema['oneOf'] ?? []) as $branch) {
            self::assertIsArray($branch);
            $this->assertObjectsAreStrict($branch);
        }
    }
}
