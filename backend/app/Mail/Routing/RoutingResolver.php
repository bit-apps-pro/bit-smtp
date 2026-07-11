<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingResolver
{
    public function resolve(RoutingContext $context, RoutingRules $rules): ?string
    {
        foreach ($rules as $rule) {
            if ($rule->matches($context)) {
                return $rule->getConnectionId();
            }
        }

        return null;
    }
}
