<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Routing\RoutingRules;
use WP_Error;

final class RoutingExplainer
{
    private LogService $logs;

    private MailSettings $settings;

    private RoutingResolver $resolver;

    private ConnectionResolver $connectionResolver;

    public function __construct(
        ?LogService $logs = null,
        ?MailSettings $settings = null,
        ?RoutingResolver $resolver = null,
        ?ConnectionResolver $connectionResolver = null
    ) {
        $this->logs = $logs ?? new LogService();
        if ($settings === null) {
            $storedSettings = Config::getOption('options');
            $settings       = MailSettings::fromArray(\is_array($storedSettings) ? $storedSettings : []);
        }
        $this->settings           = $settings;
        $this->resolver           = $resolver           ?? new RoutingResolver();
        $this->connectionResolver = $connectionResolver ?? new ConnectionResolver();
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function actual(int $logId)
    {
        if ($logId < 1) {
            return new WP_Error('bit_smtp_invalid_log_id', 'A positive log id is required.');
        }

        $log = $this->logs->get($logId);
        if ($log === null) {
            return new WP_Error('bit_smtp_log_not_found', 'The requested retained email log was not found.');
        }

        $hasMetadata = $log->source_plugin !== null
            || $log->routing_type          !== null
            || $log->routing_rule_index    !== null;

        return [
            'log_id'                     => $logId,
            'routing_metadata_available' => $hasMetadata,
            'source_plugin'              => $log->source_plugin ?? 'unknown',
            'routing_type'               => $hasMetadata ? $log->routing_type : null,
            'matched_rule_index'         => $hasMetadata && $log->routing_rule_index !== null ? (int) $log->routing_rule_index : null,
            'selected_connection_id'     => $log->connection_id ?: $log->connection,
            'fallback_chain'             => $this->settings->getFallbackConnectionIds(),
        ];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function simulate(array $input)
    {
        if (\array_key_exists('from', $input) || \array_key_exists('subject', $input)) {
            return new WP_Error(
                'bit_smtp_routing_simulation_private_input',
                'Routing simulation accepts recipient domains and source plugin only; sender addresses and subjects are private.'
            );
        }

        $domains = $this->domains($input['to_domains'] ?? null);
        if ($domains instanceof WP_Error) {
            return $domains;
        }

        $source = (string) ($input['source_plugin'] ?? 'unknown');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)?$/', $source)) {
            return new WP_Error('bit_smtp_invalid_routing_simulation', 'The simulation source plugin is invalid.');
        }

        $rules = RoutingRules::fromArray($this->routingRules());
        if ($this->requiresPrivateFields($rules)) {
            return new WP_Error(
                'bit_smtp_routing_simulation_requires_private_fields',
                'Routing simulation cannot evaluate the current rules because they require a private sender or subject.'
            );
        }

        $context = RoutingContext::fromArray([
            'recipients'   => array_map(static fn (string $domain): string => 'domain@' . $domain, $domains),
            'from'         => '',
            'subject'      => '',
            'sourcePlugin' => $source,
        ]);
        $details = [];
        foreach ($rules->all() as $index => $rule) {
            $conditions = [];
            foreach ($rule->getConditions() as $condition) {
                $conditions[] = [
                    'field'      => $condition->getField(),
                    'operator'   => $condition->getOperator(),
                    'descriptor' => $this->conditionDescriptor($condition->getField()),
                    'matches'    => $condition->matches($context),
                ];
            }
            $details[] = [
                'index'         => $index,
                'connection_id' => $rule->getConnectionId(),
                'conditions'    => $conditions,
                'matches'       => $rule->matches($context),
            ];
        }

        $decision   = $this->resolver->decide($context, $rules);
        $ordered    = $this->connectionResolver->resolveOrdered($this->settings, $decision->connectionId());
        $candidates = array_map(static fn ($connection): string => $connection->getId(), $ordered);

        return [
            'mode'                   => 'simulation',
            'rules'                  => $details,
            'matched_rule_index'     => $decision->ruleIndex(),
            'matched_connection_id'  => $decision->connectionId(),
            'routing_type'           => $decision->type(),
            'selected_connection_id' => $candidates[0] ?? null,
            'fallback_candidates'    => \array_slice($candidates, 1),
        ];
    }

    /**
     * @param mixed $candidate
     *
     * @return array<int,string>|WP_Error
     */
    private function domains($candidate)
    {
        if (!\is_array($candidate) || $candidate === [] || \count($candidate) > 50) {
            return new WP_Error('bit_smtp_invalid_routing_simulation', 'One to fifty recipient domains are required.');
        }

        $domains = [];
        foreach ($candidate as $domain) {
            $domain = strtolower((string) $domain);
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                return new WP_Error('bit_smtp_invalid_routing_simulation', 'Recipient inputs must be domains without local parts.');
            }
            $domains[$domain] = $domain;
        }

        return array_values($domains);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function routingRules(): array
    {
        $features = $this->settings->getFeatures();
        $rules    = $features['routing'] ?? [];

        return \is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    private function requiresPrivateFields(RoutingRules $rules): bool
    {
        foreach ($rules->all() as $rule) {
            foreach ($rule->getConditions() as $condition) {
                if (\in_array($condition->getField(), ['from', 'subject'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function conditionDescriptor(string $field): string
    {
        switch ($field) {
            case 'recipient':
                return 'configured recipient rule';

            case 'from':
                return 'configured sender rule';

            case 'subject':
                return 'configured subject rule';

            case 'source_plugin':
                return 'configured source plugin';

            default:
                return 'configured routing condition';
        }
    }
}
