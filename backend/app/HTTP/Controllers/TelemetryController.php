<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Telemetry;
use BitApps\SMTP\HTTP\Requests\TelemetryConsentRequest;
use BitApps\SMTP\HTTP\Services\TelemetryConsentService;

/**
 * REST endpoints for the one-time usage-data sharing prompt and its Settings switch.
 */
class TelemetryController
{
    private TelemetryConsentService $consent;

    public function __construct(?TelemetryConsentService $consent = null)
    {
        $this->consent = $consent ?? new TelemetryConsentService(Telemetry::report());
    }

    /**
     * Return whether the owner has answered the prompt and whether sharing is on.
     */
    public function status(): Response
    {
        return Response::success($this->consent->status());
    }

    /**
     * Save the owner's answer and echo the resulting status.
     */
    public function saveConsent(TelemetryConsentRequest $request): Response
    {
        $allow = filter_var($request->validated()['allow'], FILTER_VALIDATE_BOOLEAN);

        return Response::success($allow ? $this->consent->allow() : $this->consent->decline());
    }

    /**
     * Add plugin-specific counters to the weekly usage report.
     *
     * @param array<string, mixed> $additionalData
     *
     * @return array<string, mixed>
     */
    public function filterTrackingData($additionalData): array
    {
        $additionalData['test_mail_form_submitted'] = Config::getOption('test_mail_form_submitted');

        return $additionalData;
    }
}
