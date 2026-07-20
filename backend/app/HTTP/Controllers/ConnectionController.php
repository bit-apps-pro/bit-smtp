<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Validation\RequiredFieldsValidator;
use BitApps\SMTP\Plugin;
use Throwable;

class ConnectionController
{
    public function save(ConnectionSaveRequest $request)
    {
        $data     = $request->validated();
        $provider = $data['provider'] ?? '';

        if (!Plugin::instance()->providerRegistry()->has($provider)) {
            return Response::error(\sprintf(__('Unknown provider: %s', 'bit-smtp'), $provider));
        }

        $resolved = MaskedSecretResolver::apply(
            ['connections' => [$data]],
            Plugin::instance()->mailConfigService()->load()
        )['connections'][0];

        // Save-stage validation checks only user-entered required fields, so OAuth-based providers
        // can persist before consent (their access/refresh tokens are not fields() and arrive later).
        $fields          = Plugin::instance()->providerRegistry()->get($provider)->fields();
        $validationError = $this->validateConnection($resolved, new RequiredFieldsValidator($fields));
        if ($validationError !== null) {
            return $validationError;
        }

        if (!Plugin::instance()->mailConfigService()->saveConnection($data)) {
            return Response::error(__('Failed to save connection', 'bit-smtp'));
        }

        return Response::success(__('Connection saved', 'bit-smtp'));
    }

    public function delete(ConnectionDeleteRequest $request)
    {
        $data = $request->validated();

        if (!Plugin::instance()->mailConfigService()->deleteConnection($data['id'])) {
            return Response::error(__('Failed to delete connection', 'bit-smtp'));
        }

        return Response::success(__('Connection deleted', 'bit-smtp'));
    }

    public function test(ConnectionTestRequest $request)
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        try {
            $data     = $request->validated();
            $to       = $data['to'];
            $provider = $data['provider'] ?? '';
            $kind     = $data['kind']     ?? 'smtp';
            $id       = $data['id']       ?? '';

            $payload = array_diff_key($data, ['to' => true]);

            $resolved = MaskedSecretResolver::apply(
                ['connections' => [$payload]],
                Plugin::instance()->mailConfigService()->load()
            )['connections'][0];

            // Test-stage validation enforces full send-readiness via the provider's own validator
            // (e.g. Gmail cannot send without a refresh_token, SES needs a valid region).
            $validator       = Plugin::instance()->providerRegistry()->get($provider)->validator();
            $validationError = $this->validateConnection($resolved, $validator);
            if ($validationError !== null) {
                return $validationError;
            }

            $connection = Connection::fromArray(array_merge($resolved, [
                'id'       => $id !== '' ? $id : 'test_' . uniqid(),
                'provider' => $provider,
                'kind'     => $kind,
            ]));

            $transport = Plugin::instance()->providerRegistry()->get($connection->getProvider())->transport();

            $message = MailMessage::fromArray([
                'to'      => [$to],
                'subject' => 'Connection Test',
                'body'    => 'This is a test email from Bit SMTP.',
            ]);

            $result = $transport->send($message, $connection);

            if ($result->isOk()) {
                return Response::success($result->getDebug());
            }

            return Response::message(__('Connection test failed', 'bit-smtp'))
                ->error($result->getDebug() ?: [$result->getError()]);
        } catch (Throwable $e) {
            return Response::message($e->getMessage())->error([$e->getMessage()]);
        }
    }

    /**
     * Run the given validator against a resolved (sentinel-free) connection. Credentials are
     * flattened to their scalar `value` first, since the stored/incoming shape is
     * `['source' => ..., 'value' => ...]` and passing that array to the validator vacuously passes.
     */
    private function validateConnection(array $resolved, ValidatorInterface $validator): ?Response
    {
        $credentials = array_map(static function ($credential) {
            return $credential['value'] ?? '';
        }, $resolved['credentials'] ?? []);

        $errors = $validator->validate($resolved['settings'] ?? [], $credentials);

        if ($errors === []) {
            return null;
        }

        return Response::error(['errors' => $errors])->message(__('Validation failed', 'bit-smtp'));
    }
}
