<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

/**
 * Validates the site owner's usage-data sharing answer.
 */
class TelemetryConsentRequest extends Request
{
    /**
     * Require an explicit boolean so a malformed body never opts a site in.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'allow' => ['required', 'boolean'],
        ];
    }
}
