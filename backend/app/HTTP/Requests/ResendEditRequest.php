<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

/**
 * Validate edited resend (log id, recipients/subject, connection); block mail-header injection.
 */
class ResendEditRequest extends Request
{
    /**
     * Validation rules for an edited resend.
     *
     * @return array<string,string[]>
     */
    public function rules(): array
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

    /**
     * Error messages for failed rules.
     *
     * @return array<string,string>
     */
    public function messages(): array
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
            if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
                continue;
            }

            $address = preg_match('/<([^>]+)>/', $value, $matches) === 1 ? trim($matches[1]) : $value;
            if (is_email($address)) {
                $clean[strtolower($address)] = $value;
            }
        }

        return array_values($clean);
    }
}
