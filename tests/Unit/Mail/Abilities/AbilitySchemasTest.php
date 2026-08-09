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

    public function testTimingAndRetentionOutputContractsMatchTheAggregateAnalyticsResponses(): void
    {
        $overview = AbilitySchemas::overviewOutput();
        self::assertSame(['type' => 'boolean'], $overview['properties']['logging_enabled']);
        self::assertSame(['earliest', 'latest'], $overview['properties']['retained_records']['required']);
        self::assertSame('date-time', $overview['properties']['retained_records']['properties']['earliest']['oneOf'][0]['format']);
        self::assertSame(10, $overview['properties']['busiest_hours']['maxItems']);
        self::assertSame(23, $overview['properties']['busiest_hours']['items']['properties']['hour']['maximum']);
        self::assertSame(7, $overview['properties']['busiest_weekdays']['items']['properties']['weekday']['maximum']);

        $plugin = AbilitySchemas::pluginOutput();
        self::assertSame(10, $plugin['properties']['busiest_hours']['maxItems']);
        self::assertSame(10, $plugin['properties']['busiest_weekdays']['maxItems']);

        $observations = AbilitySchemas::anomaliesOutput()['properties']['observations']['items'];
        self::assertContains('hourly_distribution_shift', $observations['properties']['type']['enum']);
        self::assertContains('weekday_distribution_shift', $observations['properties']['type']['enum']);
        self::assertSame(23, $observations['oneOf'][4]['properties']['hour']['maximum']);
        self::assertSame(7, $observations['oneOf'][5]['properties']['weekday']['maximum']);
        self::assertSame(['type', 'hour', 'current', 'prior', 'current_percentage', 'prior_percentage', 'percentage_point_change'], $observations['oneOf'][4]['required']);
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
