<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingRule;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutingRuleTest extends BaseUnitTestCase
{
    public function testFromArrayAndToArrayRoundTrip(): void
    {
        $data = [
            'conditions' => [
                ['field' => 'recipient', 'operator' => 'equals', 'value' => 'jane@example.com'],
                ['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce'],
            ],
            'connectionId' => 'conn-1',
        ];

        $rule = RoutingRule::fromArray($data);

        $this->assertSame($data, $rule->toArray());
        $this->assertSame('conn-1', $rule->getConnectionId());
    }

    public function testMatchesReturnsTrueWhenAllConditionsMatch(): void
    {
        $rule = RoutingRule::fromArray([
            'conditions' => [
                ['field' => 'recipient', 'operator' => 'equals', 'value' => 'jane@example.com'],
                ['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce'],
            ],
            'connectionId' => 'conn-1',
        ]);

        $this->assertTrue($rule->matches($this->context()));
    }

    public function testMatchesReturnsFalseWhenOneConditionFails(): void
    {
        $rule = RoutingRule::fromArray([
            'conditions' => [
                ['field' => 'recipient', 'operator' => 'equals', 'value' => 'jane@example.com'],
                ['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd'],
            ],
            'connectionId' => 'conn-1',
        ]);

        $this->assertFalse($rule->matches($this->context()));
    }

    public function testMatchesReturnsFalseWhenConditionsAreEmpty(): void
    {
        $rule = RoutingRule::fromArray([
            'conditions'   => [],
            'connectionId' => 'conn-1',
        ]);

        $this->assertFalse($rule->matches($this->context()));
    }

    private function context(): RoutingContext
    {
        return RoutingContext::fromArray([
            'recipients'   => ['jane@example.com'],
            'from'         => 'billing@example.com',
            'subject'      => 'Your Invoice is Ready',
            'sourcePlugin' => 'woocommerce',
        ]);
    }
}
