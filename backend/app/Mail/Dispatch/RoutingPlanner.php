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
 * Plans which connections a send is attempted over and in what order, and records/advances the routing
 * decision. The mutable SendContext is always passed in (never cached) so the bridge stays the sole
 * owner of the single per-send decision the controller reads back.
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
     * Resolve the connection the routing rules select for this send, updating the context's routing
     * decision as a side effect, and return its id (or null when no rule matched / routing is off).
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
     * Seed the context with the send's detected source before dispatch, so a natively-deferred send
     * still logs where it originated. Skipped only when there is nothing to record: logging is off and
     * the plugin is disabled or has no routing rules.
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
     * Stamp the context's routing decision as 'native' for a send that fell through to core wp_mail,
     * seeding a fresh decision from the detected source when none was captured earlier.
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
     * Promote the routing decision to 'fallback' once the failover loop moves past the connection the
     * router originally selected, so the log reflects that a lower-priority connection actually sent.
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
     * The first sendable connection in priority order — the default the router falls back to when no
     * rule names a connection.
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
     * Drop connections that cannot send yet so an incomplete setup falls through to native wp_mail
     * instead of forcing a broken send.
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
     * The de-duplicated, non-empty connection ids the router would try in priority order for this send:
     * the rule-matched/preferred connection, then the configured default, then each listed fallback.
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
