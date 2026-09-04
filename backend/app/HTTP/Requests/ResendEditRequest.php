<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

/**
 * Validates an edited manual resend: the log id, edited recipients/subject, and the required sending
 * connection. recipients() validates each address (the validator has no array-of-email rule) and
 * `sanitize:text` strips CR/LF from the subject — both block mail-header injection.
 */
class ResendEditRequest extends Request
{
    public function rules()
    {
        return [
            'id'            => ['required', 'integer'],
            'to'            => ['required', 'array'],
            'cc'            => ['nullable', 'array'],
            'bcc'           => ['nullable', 'array'],
            'subject'       => ['required', 'string', 'sanitize:text'],
            'connection_id' => ['required', 'string', 'sanitize:text'],
        ];
    }

    public function messages()
    {
        return [
            'id.required'            => 'A log id is required',
            'to.required'            => 'At least one recipient is required',
            'subject.required'       => 'Subject is required',
            'connection_id.required' => 'A sending connection is required',
        ];
    }

    /**
     * The valid, de-duplicated email addresses for a recipient field (to/cc/bcc), dropping invalid ones.
     *
     * @return string[]
     */
    public function recipients(string $field): array
    {
        $values = (array) ($this->validated()[$field] ?? []);

        $clean = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            // Reject CR/LF: it can't belong to one recipient and would inject extra Cc/Bcc headers.
            if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
                continue;
            }

            // Validate the address inside a "Name <addr>" form so named recipients survive the edit.
            $address = preg_match('/<([^>]+)>/', $value, $matches) === 1 ? trim($matches[1]) : $value;
            if (is_email($address)) {
                $clean[strtolower($address)] = $value;
            }
        }

        return array_values($clean);
    }
}
