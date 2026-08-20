<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class ZeptoWebhookAdapter implements WebhookAdapterInterface
{
    /**
     * @var array<string, array{0: string, 1: bool}> event name => [status, terminal]
     */
    private const EVENT_MAP = [
        'softbounce' => [DeliveryStatus::DEFERRED, false],
        'hardbounce' => [DeliveryStatus::BOUNCED, true],
        'delivered'  => [DeliveryStatus::DELIVERED, true],
    ];

    public function parseEvents(WebhookRequest $request): array
    {
        $payload  = $this->payload($request);
        $messages = $payload['event_message'] ?? [];
        if (!\is_array($messages)) {
            return [];
        }

        // Collapse event_name to the distinct recognised statuses BEFORE the message loop. Both
        // event_name and event_message are attacker-sized arrays from the same webhook body, so
        // pairing every name with every message would be quadratic (a 1MB body → billions of
        // iterations → OOM/timeout). Only the three mapped names can emit an event and the DB dedups
        // duplicates, so this bounds the inner loop to at most three iterations regardless of input.
        $names = [];
        foreach ((array) ($payload['event_name'] ?? []) as $name) {
            $name = strtolower((string) $name);
            if (isset(self::EVENT_MAP[$name])) {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }

        $events = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }

            $info = \is_array($message['email_info'] ?? null) ? $message['email_info'] : [];
            foreach (array_keys($names) as $name) {
                $events[] = DeliveryEvent::fromArray([
                    'message_id'  => $info['email_reference']  ?? ($message['request_id'] ?? null),
                    'tracking_id' => $info['client_reference'] ?? null,
                    'recipient'   => $this->recipient($info),
                    'status'      => self::EVENT_MAP[$name][0],
                    'terminal'    => self::EVENT_MAP[$name][1],
                    'detail'      => $this->detail($message),
                    'occurred_at' => $info['processed_time'] ?? null,
                ]);
            }
        }

        return $events;
    }

    private function payload(WebhookRequest $request): array
    {
        $payload = $request->decoded();
        if ($payload !== []) {
            return $payload;
        }

        parse_str($request->rawBody(), $form);
        $decoded = isset($form['data']) ? json_decode((string) $form['data'], true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    private function recipient(array $info): string
    {
        foreach (($info['to'] ?? []) as $item) {
            if (isset($item['email_address']['address'])) {
                return (string) $item['email_address']['address'];
            }
        }

        return '';
    }

    private function detail(array $message): ?string
    {
        $details = $message['event_data'][0]['details'][0] ?? [];

        return \is_array($details) ? ($details['diagnostic_message'] ?? ($details['reason'] ?? null)) : null;
    }
}
