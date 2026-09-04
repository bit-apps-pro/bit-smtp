<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingResolver
{
    public function decide(RoutingContext $context, RoutingRules $rules): RoutingDecision
    {
        /**
         * @var RoutingRule $rule
         */
        foreach ($rules->all() as $index => $rule) {
            if ($rule->matches($context)) {
                return new RoutingDecision($context->getSourcePlugin(), $rule->getConnectionId(), 'rule', $index);
            }
        }

        return new RoutingDecision($context->getSourcePlugin(), null, 'default', null);
    }

    public function resolve(RoutingContext $context, RoutingRules $rules): ?string
    {
        return $this->decide($context, $rules)->connectionId();
    }
}
