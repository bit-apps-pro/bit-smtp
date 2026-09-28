<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

/**
 * Shared assembly for importers targeting Bit SMTP's `other_smtp` connection.
 */
abstract class AbstractSmtpImporter implements PluginImporterInterface
{
    /**
     * Read a WordPress option as an array, coercing anything else (absent/scalar) to an empty array.
     *
     * @return array<string,mixed>
     */
    protected function optionArray(string $name): array
    {
        $value = get_option($name, []);

        return \is_array($value) ? $value : [];
    }

    /**
     * A nested array sub-key, or an empty array when absent or non-array.
     *
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    protected function subArray(array $source, string $key): array
    {
        return isset($source[$key]) && \is_array($source[$key]) ? $source[$key] : [];
    }

    /**
     * Normalize a source encryption value to Bit SMTP's `none|ssl|tls`, defaulting unknowns to none.
     *
     * @param mixed $value
     */
    protected function normalizeEncryption($value): string
    {
        $encryption = strtolower(trim((string) $value));

        return \in_array($encryption, ['ssl', 'tls'], true) ? $encryption : 'none';
    }

    /**
     * Assemble canonical `other_smtp` connection array; password encrypted at rest via MailConfigService.
     *
     * @param array{name:string,fromEmail:string,fromName:string,replyToEmail?:string,host:string,port:int,encryption:mixed,auth:bool,username:string,password:string} $fields
     *
     * @return array<string,mixed>
     */
    protected function toSmtpConnection(array $fields): array
    {
        return [
            'id'           => '',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => $fields['name'],
            'enabled'      => true,
            'fromEmail'    => $fields['fromEmail'],
            'fromName'     => $fields['fromName'],
            'replyToEmail' => $fields['replyToEmail'] ?? '',
            'settings'     => [
                'host'       => $fields['host'],
                'port'       => $fields['port'],
                'encryption' => $this->normalizeEncryption($fields['encryption']),
                'auth'       => $fields['auth'],
                'username'   => $fields['username'],
                'smtp_debug' => false,
            ],
            'credentials' => [
                'password' => ['source' => 'database', 'value' => $fields['password']],
            ],
        ];
    }
}
