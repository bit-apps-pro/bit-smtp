<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\ConnectionDeleteRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionSaveRequest;
use BitApps\SMTP\HTTP\Requests\ConnectionTestRequest;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Plugin;

class ConnectionController
{
    public function save(ConnectionSaveRequest $request)
    {
        $data     = $request->validated();
        $provider = $data['provider'] ?? '';

        if (!Plugin::instance()->providerRegistry()->has($provider)) {
            return Response::error(sprintf(__('Unknown provider: %s', 'bit-smtp'), $provider));
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
            $kind     = $data['kind'] ?? 'smtp';
            $id       = $data['id'] ?? '';

            $payload = array_diff_key($data, ['to' => true]);

            $resolved = MaskedSecretResolver::apply(
                ['connections' => [$payload]],
                Plugin::instance()->mailConfigService()->load()
            )['connections'][0];

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
        } catch (\Throwable $e) {
            return Response::message($e->getMessage())->error([$e->getMessage()]);
        }
    }
}
