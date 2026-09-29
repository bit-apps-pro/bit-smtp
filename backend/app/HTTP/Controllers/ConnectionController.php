<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionWebhookCreateRequest;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Credentials\CredentialValues;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
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
            // translators: %s: provider slug
            return Response::error(\sprintf(__('Unknown provider: %s', 'bit-smtp'), $provider));
        }

        $resolved = MaskedSecretResolver::apply(
            ['connections' => [$data]],
            Plugin::instance()->mailConfigService()->load()
        )['connections'][0];

        $fields          = Plugin::instance()->providerRegistry()->get($provider)->fields();
        $validationError = $this->validateConnection($resolved, new RequiredFieldsValidator($fields));
        if ($validationError !== null) {
            return $validationError;
        }

        $connId = Plugin::instance()->mailConfigService()->upsertConnection($data);
        if ($connId === null) {
            return Response::error(__('Failed to save connection', 'bit-smtp'));
        }

        $connection = Plugin::instance()->mailConfigService()->connectionById($connId);
        $webhook    = $connection !== null
            ? Plugin::instance()->webhookProvisioningService()->provisionOnSave($connection)
            : ['status' => 'skipped'];

        return Response::success(['id' => $connId, 'webhook' => $webhook])->message(__('Connection saved', 'bit-smtp'));
    }

    public function delete(ConnectionDeleteRequest $request)
    {
        $data = $request->validated();

        if (!Plugin::instance()->mailConfigService()->deleteConnection($data['id'])) {
            return Response::error(__('Failed to delete connection', 'bit-smtp'));
        }

        return Response::success(__('Connection deleted', 'bit-smtp'));
    }

    public function createWebhook(ConnectionWebhookCreateRequest $request)
    {
        try {
            $connection = Plugin::instance()->mailConfigService()->connectionById($request->validated()['id']);
            if ($connection === null) {
                return Response::error(__('A saved connection is required.', 'bit-smtp'));
            }

            $result = Plugin::instance()->webhookProvisioningService()->ensureFor($connection);

            return Response::success($result)->message(
                !empty($result['created'])
                    ? __('Webhook created.', 'bit-smtp')
                    : __('Existing webhook reused.', 'bit-smtp')
            );
        } catch (Throwable $e) {
            return Response::error([])->message($e->getMessage());
        }
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

            $detail = $result->getError();
            $detail = ($detail !== null && $detail !== '') ? $detail : __('Connection test failed', 'bit-smtp');

            return Response::message($detail)->error([
                'debug'         => $result->getDebug() ?: [$detail],
                'failure_class' => (new FailureClassifier())->classify($result),
            ]);
        } catch (Throwable $e) {
            return Response::message($e->getMessage())->error(['debug' => [$e->getMessage()]]);
        }
    }

    /**
     * Best-effort poll for delivery status; failure degrades to null instead of failing send.
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
     * Return error if delivery is negative (deferred/blocked/bounced/spam), else success.
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
     * Build success response with optional delivery outcome; degrade to success if checker fails.
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
     * Poll for terminal delivery status, retaining earlier non-null results over later nulls.
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
     * Validate connection with flattened scalar credentials (extracted from source/value pairs).
     */
    private function validateConnection(array $resolved, ValidatorInterface $validator): ?Response
    {
        $errors = $validator->validate($resolved['settings'] ?? [], CredentialValues::flatten($resolved['credentials'] ?? []));

        if ($errors === []) {
            return null;
        }

        return Response::error(['errors' => $errors])->message(__('Validation failed', 'bit-smtp'));
    }
}
