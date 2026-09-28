<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Routing\RoutingRules;

/**
 * Plan send connection order and record routing decision; mutable SendContext passed (never cached).
 */
final class RoutingPlanner
{
    private ConnectionResolver $connectionResolver;

    private RoutingResolver $routingResolver;

    private MailSourceDetector $sourceDetector;

    private ConnectionSendability $sendability;

    public function __construct(
        ConnectionResolver $connectionResolver,
        RoutingResolver $routingResolver,
        MailSourceDetector $sourceDetector,
        ConnectionSendability $sendability
    ) {
        $this->connectionResolver = $connectionResolver;
        $this->routingResolver    = $routingResolver;
        $this->sourceDetector     = $sourceDetector;
        $this->sendability        = $sendability;
    }

    /**
     * Resolve routing-selected connection id, updating context's routing decision as side effect.
     */
    public function routedConnectionId(MailMessage $message, MailSettings $settings, SendContext $context): ?string
    {
        $rules    = $this->routingRules($settings);
        $decision = $context->getRoutingDecision();

        if ($rules === null) {
            if ($decision !== null) {
                $context->setRoutingDecision(new RoutingDecision(
                    $decision->sourcePlugin(),
                    $this->defaultConnectionId($settings),
                    'default',
                    null
                ));
            }

            return null;
        }

        if ($decision === null) {
            $decision = new RoutingDecision($this->sourceDetector->detect(), null, 'native', null);
        }

        $routingContext = RoutingContext::fromArray([
            'recipients'   => $message->getTo(),
            'from'         => $message->getFrom() ?? '',
            'subject'      => $message->getSubject(),
            'sourcePlugin' => $decision->sourcePlugin(),
        ]);
        $decision     = $this->routingResolver->decide($routingContext, $rules);
        $connectionId = $decision->connectionId() ?? $this->defaultConnectionId($settings);

        $context->setRoutingDecision(new RoutingDecision(
            $decision->sourcePlugin(),
            $connectionId,
            $decision->type(),
            $decision->ruleIndex()
        ));

        return $decision->connectionId();
    }

    /**
     * Seed context with detected source before dispatch for proper logging of natively-deferred sends.
     */
    public function captureSourceForSend(MailSettings $settings, SendContext $context, bool $loggingEnabled): void
    {
        if (!$loggingEnabled && (!$settings->isEnabled() || $this->routingRules($settings) === null)) {
            return;
        }

        $context->setRoutingDecision(new RoutingDecision(
            $this->sourceDetector->detect(),
            null,
            'native',
            null
        ));
    }

    /**
     * Stamp routing decision as 'native' for core wp_mail sends, seeding from detected source if needed.
     */
    public function captureNativeRoutingDecision(SendContext $context): void
    {
        $decision = $context->getRoutingDecision();
        if ($decision === null) {
            $decision = new RoutingDecision($this->sourceDetector->detect(), null, 'native', null);
        } else {
            $decision = $decision->withType('native');
        }

        $context->setRoutingDecision($decision);
    }

    /**
     * Promote routing decision to 'fallback' once failover uses a non-selected connection.
     */
    public function advanceRoutingDecision(Connection $connection, SendContext $context): void
    {
        $decision = $context->getRoutingDecision();
        if ($decision === null || $decision->type() === 'fallback' || $decision->type() === 'native') {
            return;
        }

        if ($decision->connectionId() !== null && $decision->connectionId() !== $connection->getId()) {
            $context->setRoutingDecision($decision->withType('fallback'));
        }
    }

    /**
     * First sendable connection in priority order; router fallback when no rule matches.
     */
    public function defaultConnectionId(MailSettings $settings): ?string
    {
        $connections = $this->sendableConnections($settings);

        return isset($connections[0]) ? $connections[0]->getId() : null;
    }

    /**
     * The configured routing rules, or null when none are set (routing off).
     */
    public function routingRules(MailSettings $settings): ?RoutingRules
    {
        $rawRules = $settings->getFeatures()['routing'] ?? [];
        if (!\is_array($rawRules) || $rawRules === []) {
            return null;
        }

        return RoutingRules::fromArray($rawRules);
    }

    /**
     * Drop connections that cannot send; incomplete setups fall through to native wp_mail.
     *
     * @return Connection[]
     */
    public function sendableConnections(MailSettings $settings, ?string $preferredId = null): array
    {
        return array_values(array_filter(
            $this->connectionResolver->resolveOrdered($settings, $preferredId),
            fn (Connection $connection): bool => $this->sendability->isSendable($connection)
        ));
    }

    /**
     * De-duplicated connection ids router tries in priority order (preferred, default, fallbacks).
     *
     * @return string[]
     */
    public function priorityChainIds(MailSettings $settings, ?string $preferredId): array
    {
        $ids = array_merge(
            [(string) $preferredId, $settings->getDefaultConnectionId()],
            array_map('strval', $settings->getFallbackConnectionIds())
        );

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }
}
