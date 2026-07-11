<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\RoutingRule;
use BitApps\SMTP\Mail\Routing\RoutingRules;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutingRulesTest extends BaseUnitTestCase
{
    public function testFromArrayBuildsRoutingRuleInstances(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'from', 'operator' => 'equals', 'value' => 'billing@example.com']],
                'connectionId' => 'conn-1',
            ],
            [
                'conditions'   => [['field' => 'from', 'operator' => 'equals', 'value' => 'support@example.com']],
                'connectionId' => 'conn-2',
            ],
        ]);

        $all = $rules->all();

        $this->assertCount(2, $all);
        $this->assertContainsOnlyInstancesOf(RoutingRule::class, $all);
        $this->assertSame('conn-1', $all[0]->getConnectionId());
        $this->assertSame('conn-2', $all[1]->getConnectionId());
    }

    public function testFromArrayWithEmptyArrayProducesEmptyCollection(): void
    {
        $rules = RoutingRules::fromArray([]);

        $this->assertSame([], $rules->all());
    }

    public function testIsIterable(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'from', 'operator' => 'equals', 'value' => 'billing@example.com']],
                'connectionId' => 'conn-1',
            ],
        ]);

        $ids = [];
        foreach ($rules as $rule) {
            $ids[] = $rule->getConnectionId();
        }

        $this->assertSame(['conn-1'], $ids);
    }
}
