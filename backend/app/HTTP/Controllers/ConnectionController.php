<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Status\StatusCheckerRegistry;
use BitApps\SMTP\Mail\Validation\RequiredFieldsValidator;
use BitApps\SMTP\Plugin;
use Throwable;

class ConnectionController
{
    private const DELIVERY_POLL_ATTEMPTS = 3;

    private const DELIVERY_POLL_INTERVAL = 2;

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
                return $this->successResponse($provider, $connection, $result);
            }

            return Response::message(__('Connection test failed', 'bit-smtp'))
                ->error($result->getDebug() ?: [$result->getError()]);
        } catch (Throwable $e) {
            return Response::message($e->getMessage())->error([$e->getMessage()]);
        }
    }

    /**
     * Best-effort poll of the provider's real outcome, isolated so a checker/poll failure degrades
     * to null (today's plain-success behaviour) instead of failing the already-successful send.
     */
    protected function resolveDelivery(?MessageStatusCheckerInterface $checker, ?string $messageId, Connection $connection): ?DeliveryStatus
    {
        if ($checker === null || $messageId === null) {
            return null;
        }

        try {
            return $this->pollDelivery($checker, $messageId, $connection);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A negative outcome (deferred/blocked/bounced/spam) flips the result to an error so the caller
     * learns the API's 2xx did not mean the mail actually landed; otherwise it stays a success.
     */
    protected function deliveryResponse(SendResult $result, ?DeliveryStatus $delivery): Response
    {
        if ($delivery !== null && $delivery->isNegative()) {
            return Response::message($delivery->detail() ?: __('Message not delivered', 'bit-smtp'))
                ->error(['debug' => $result->getDebug(), 'delivery' => $this->deliveryPayload($delivery)]);
        }

        return Response::success([
            'debug'    => $result->getDebug(),
            'delivery' => $delivery === null ? null : $this->deliveryPayload($delivery),
        ]);
    }

    /**
     * Build the response for an accepted send, upgrading it with the provider's real delivery
     * outcome when available. Obtaining the registry (Plugin singleton) is guarded here too, so
     * even that failing degrades to plain success rather than failing an already-successful send.
     */
    private function successResponse(string $provider, Connection $connection, SendResult $result): Response
    {
        $delivery = null;

        try {
            $registry = new StatusCheckerRegistry(Plugin::instance()->apiClient());
            $delivery = $this->resolveDelivery(
                $registry->checkerFor($provider),
                $registry->messageIdFrom($provider, $result->getDebug()),
                $connection
            );
        } catch (Throwable $e) {
            $delivery = null;
        }

        return $this->deliveryResponse($result, $delivery);
    }

    /**
     * Poll a few times because Brevo's event feed lags the send, stopping as soon as a terminal
     * outcome lands and never discarding an earlier non-null result to a later null.
     */
    private function pollDelivery(MessageStatusCheckerInterface $checker, string $messageId, Connection $connection): ?DeliveryStatus
    {
        $delivery = null;
        for ($attempt = 0; $attempt < self::DELIVERY_POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                sleep(self::DELIVERY_POLL_INTERVAL);
            }

            $current = $checker->check($messageId, $connection);
            if ($current !== null) {
                $delivery = $current;
            }

            if ($delivery !== null && ($delivery->state() === DeliveryStatus::DELIVERED || $delivery->isNegative())) {
                break;
            }
        }

        return $delivery;
    }

    private function deliveryPayload(DeliveryStatus $delivery): array
    {
        return ['state' => $delivery->state(), 'detail' => $delivery->detail()];
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
