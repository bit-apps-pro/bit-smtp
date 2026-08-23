<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Plugin;
use WP_Error;

/**
 * REST endpoints wrapping MailAnalyticsService for the first-party analytics dashboard.
 *
 * Error shape: every failure returns Response::error() with the WP_Error's code/message surfaced
 * via ->code()/->message(), so the frontend can branch on a stable code rather than parsing text.
 * `bit_smtp_logging_disabled` is intentionally shipped as HTTP 200 (not 4xx/5xx): it is an expected,
 * non-failure state (no logs to aggregate yet), and the UI is meant to render an empty "enable
 * logging" state for it rather than a generic error toast. Invalid input/range (from the query
 * factory) is a genuine client error -> 422. Anything else (e.g. a DB aggregate failure) -> 500.
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
     * Build the query from the request, dispatch it to the named service method, and translate the
     * resulting array|WP_Error into a Response.
     */
    private function respond(Request $request, string $method): Response
    {
        // A fresh factory per request: it captures the site timezone/current time, which must not be
        // retained stale across requests (mirrors AbilitiesProvider::queryFactory()).
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
