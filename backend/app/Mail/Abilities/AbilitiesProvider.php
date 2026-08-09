<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Abilities;

use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Mail\Analytics\RoutingExplainer;
use Throwable;
use WP_Error;

/**
 * Feature-gated adapter between WordPress' Abilities API and Bit SMTP's bounded analytics services.
 */
final class AbilitiesProvider
{
    private const CATEGORY = 'bit-smtp-analytics';

    /**
     * Expected callback errors that result from a caller's input or current capability state.
     * Database and unexpected service failures intentionally remain 500 below.
     *
     * @var array<int,string>
     */
    private const CLIENT_ERROR_CODES = [
        'bit_smtp_invalid_analytics_dimension',
        'bit_smtp_invalid_analytics_input',
        'bit_smtp_invalid_analytics_range',
        'bit_smtp_invalid_log_id',
        'bit_smtp_invalid_routing_mode',
        'bit_smtp_invalid_routing_simulation',
        'bit_smtp_logging_disabled',
        'bit_smtp_missing_analytics_plugin',
    ];

    private ?AnalyticsQueryFactory $queryFactory;

    private ?MailAnalyticsService $analytics;

    private ?RoutingExplainer $routing;

    public function __construct(
        ?AnalyticsQueryFactory $queryFactory = null,
        ?MailAnalyticsService $analytics = null,
        ?RoutingExplainer $routing = null
    ) {
        $this->queryFactory = $queryFactory;
        $this->analytics    = $analytics;
        $this->routing      = $routing;
    }

    /**
     * Register hooks only when WordPress supplies the entire Abilities API.
     */
    public function register(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        Hooks::addAction('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        Hooks::addAction('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    /**
     * Must run on the wp_abilities_api_categories_init action.
     */
    public function registerCategory(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Bit SMTP Email Analytics', 'bit-smtp'),
            'description' => __('Read-only aggregate analysis of retained Bit SMTP email logs.', 'bit-smtp'),
        ]);
    }

    /**
     * Must run on the wp_abilities_api_init action.
     */
    public function registerAbilities(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        foreach ($this->definitions() as $name => $definition) {
            wp_register_ability($name, $definition);
        }
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function executeOverview(array $input)
    {
        return $this->analytics($input, 'overview');
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function executePlugin(array $input)
    {
        return $this->analytics($input, 'plugin');
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function executeDeliverability(array $input)
    {
        return $this->analytics($input, 'deliverability');
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function executeRouting(array $input)
    {
        try {
            if (\array_key_exists('log_id', $input)) {
                return $this->restError($this->routing()->actual((int) $input['log_id']));
            }

            if (\array_key_exists('to_domains', $input)) {
                return $this->restError($this->routing()->simulate($input));
            }
        } catch (Throwable $exception) {
            return $this->serviceError();
        }

        return $this->restError(new WP_Error('bit_smtp_invalid_routing_mode', 'Choose either a retained log id or a routing simulation.'));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    public function executeAnomalies(array $input)
    {
        return $this->analytics($input, 'anomalies');
    }

    private function isAvailable(): bool
    {
        return \function_exists('wp_register_ability') && \function_exists('wp_register_ability_category');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function definitions(): array
    {
        return [
            'bit-smtp/get-email-analytics' => $this->definition(
                __('Get Email Analytics', 'bit-smtp'),
                __('Returns aggregate activity, acceptance, delivery, source, and connection metrics from retained Bit SMTP email logs.', 'bit-smtp'),
                AbilitySchemas::overviewInput(),
                AbilitySchemas::overviewOutput(),
                [$this, 'executeOverview']
            ),
            'bit-smtp/analyze-plugin-email' => $this->definition(
                __('Analyze Plugin Email', 'bit-smtp'),
                __('Returns aggregate retained-email activity for one normalized source plugin.', 'bit-smtp'),
                AbilitySchemas::pluginInput(),
                AbilitySchemas::pluginOutput(),
                [$this, 'executePlugin']
            ),
            'bit-smtp/analyze-deliverability' => $this->definition(
                __('Analyze Deliverability', 'bit-smtp'),
                __('Returns aggregate send acceptance and verified delivery outcomes from retained Bit SMTP email logs.', 'bit-smtp'),
                AbilitySchemas::deliverabilityInput(),
                AbilitySchemas::deliverabilityOutput(),
                [$this, 'executeDeliverability']
            ),
            'bit-smtp/explain-routing' => $this->definition(
                __('Explain Email Routing', 'bit-smtp'),
                __('Explains a retained routing decision or simulates current routing without sending email.', 'bit-smtp'),
                AbilitySchemas::routingInput(),
                AbilitySchemas::routingOutput(),
                [$this, 'executeRouting']
            ),
            'bit-smtp/detect-email-anomalies' => $this->definition(
                __('Detect Email Anomalies', 'bit-smtp'),
                __('Compares aggregate retained-email activity with the immediately preceding equal period.', 'bit-smtp'),
                AbilitySchemas::anomaliesInput(),
                AbilitySchemas::anomaliesOutput(),
                [$this, 'executeAnomalies']
            ),
        ];
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @param array<string,mixed> $outputSchema
     * @param callable            $execute
     *
     * @return array<string,mixed>
     */
    private function definition(string $label, string $description, array $inputSchema, array $outputSchema, callable $execute): array
    {
        return [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => $inputSchema,
            'output_schema'       => $outputSchema,
            'execute_callback'    => $execute,
            'permission_callback' => static function (): bool {
                return current_user_can('manage_options');
            },
            'meta' => [
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
                'show_in_rest' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|WP_Error
     */
    private function analytics(array $input, string $method)
    {
        try {
            $query = $this->queryFactory()->fromInput($input);
            if ($query instanceof WP_Error) {
                return $this->restError($query);
            }

            return $this->restError($this->analyticsService()->{$method}($query));
        } catch (Throwable $exception) {
            return $this->serviceError();
        }
    }

    private function queryFactory(): AnalyticsQueryFactory
    {
        // A supplied factory is a deliberate test/customization dependency. The default factory
        // captures the site timezone and current time, so create it per execution rather than
        // retaining stale values when this provider is used by a long-running process.
        return $this->queryFactory ?? new AnalyticsQueryFactory();
    }

    private function analyticsService(): MailAnalyticsService
    {
        return $this->analytics ??= new MailAnalyticsService(new MailAnalyticsRepository());
    }

    private function routing(): RoutingExplainer
    {
        return $this->routing ?? new RoutingExplainer();
    }

    private function serviceError(): WP_Error
    {
        return new WP_Error('bit_smtp_analytics_service_error', 'The email analytics service could not process the request.', ['status' => 500]);
    }

    /**
     * @param array<string,mixed>|WP_Error $result
     *
     * @return array<string,mixed>|WP_Error
     */
    private function restError($result)
    {
        if (!($result instanceof WP_Error)) {
            return $result;
        }

        $code = $result->get_error_code();
        if ($code === 'bit_smtp_log_not_found') {
            $status = 404;
        } elseif (\in_array($code, self::CLIENT_ERROR_CODES, true)) {
            $status = 400;
        } else {
            $status = 500;
        }

        $data = $result->get_error_data($code);
        $data = \is_array($data) ? $data : [];
        $data['status'] = $status;
        $result->add_data($data, $code);

        return $result;
    }
}
