<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Plugin;
use WP_Error;

/**
 * Analytics REST endpoints; logs-disabled returns 200 (expected state), invalid-input 422, others 500.
 */
class AnalyticsController
{
    private const LOGGING_DISABLED_CODE = 'bit_smtp_logging_disabled';

    private const LOGGING_DISABLED_STATUS = 200;

    private const INVALID_INPUT_STATUS = 422;

    private const SERVICE_ERROR_STATUS = 500;

    private ?MailAnalyticsService $analytics;

    public function __construct(?MailAnalyticsService $analytics = null)
    {
        $this->analytics = $analytics;
    }

    /**
     * Aggregate volume/acceptance/delivery metrics, time series, and top sources/connections.
     */
    public function overview(Request $request): Response
    {
        return $this->respond($request, 'overview');
    }

    /**
     * Send acceptance and verified delivery outcomes, broken down by source and connection.
     */
    public function deliverability(Request $request): Response
    {
        return $this->respond($request, 'deliverability');
    }

    /**
     * Current-vs-prior-equal-period comparison observations (volume, failure rate, timing shifts).
     */
    public function anomalies(Request $request): Response
    {
        return $this->respond($request, 'anomalies');
    }

    /**
     * Open/click engagement totals with the honest human-vs-automated split and engagement rates.
     */
    public function engagement(Request $request): Response
    {
        return $this->respond($request, 'engagement');
    }

    /**
     * Build query from request, dispatch to service method, translate result to Response.
     */
    private function respond(Request $request, string $method): Response
    {
        $query = (new AnalyticsQueryFactory())->fromInput($request->all());
        if ($query instanceof WP_Error) {
            return $this->errorResponse($query, self::INVALID_INPUT_STATUS);
        }

        $result = $this->service()->{$method}($query);
        if ($result instanceof WP_Error) {
            return $this->errorResponse($result, $this->statusFor($result));
        }

        return Response::success($result);
    }

    private function statusFor(WP_Error $error): int
    {
        return $error->get_error_code() === self::LOGGING_DISABLED_CODE
            ? self::LOGGING_DISABLED_STATUS
            : self::SERVICE_ERROR_STATUS;
    }

    private function errorResponse(WP_Error $error, int $httpStatus): Response
    {
        return Response::error($error->get_error_data() ?: [], $httpStatus)
            ->code($error->get_error_code())
            ->message(implode('; ', $error->get_error_messages()));
    }

    /**
     * The injected MailAnalyticsService, or the plugin's shared container singleton on first use.
     */
    private function service(): MailAnalyticsService
    {
        return $this->analytics ??= Plugin::instance()->app()->make(MailAnalyticsService::class);
    }
}
