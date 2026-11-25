<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOrderAlarm;
use App\Models\Installation;
use App\Models\OpenOrder;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/** Webhook da Cardápio Web: persiste/deduplica e delega o envio à fila. */
class WebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = (string) config('cardapioweb.webhook_token');
        $given = (string) $request->header('X-Webhook-Token');
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $eventId = (string) $request->input('event_id');
        $type = (string) $request->input('event_type');
        $merchantId = (string) $request->input('merchant_id');
        if ($eventId === '' || $type === '' || $merchantId === '') {
            return response()->json(['ok' => true, 'ignored' => 'invalid_payload']);
        }

        try {
            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $type,
                'merchant_id' => $merchantId,
                'order_id' => (string) $request->input('order_id'),
                'order_status' => (string) $request->input('order_status', $request->input('status', '')),
                'payload' => $request->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $event->update(['result' => $this->route($event)]);

        return response()->json(['ok' => true]);
    }

    private function route(WebhookEvent $event): string
    {
        $installation = Installation::where('company_external_id', $event->merchant_id)
            ->where('status', 'active')
            ->first();
        if (! $installation) {
            return 'unknown_merchant';
        }

        if ($event->event_type === 'ORDER_STATUS_UPDATED') {
            $this->updateOpenOrder($installation, $event);

            return 'open_order_updated';
        }

        if ($event->event_type !== 'ORDER_CREATED') {
            return 'ignored_type';
        }

        if (in_array($event->order_status, config('cardapioweb.ignored_statuses'), true)) {
            return 'ignored_status';
        }

        $pending = in_array($event->order_status, config('cardapioweb.pending_statuses'), true);
        $scheduled = in_array($event->order_status, config('cardapioweb.scheduled_statuses'), true);
        $orderId = (string) $event->order_id;

        if ($pending || $scheduled) {
            $openOrder = OpenOrder::firstOrNew([
                'installation_id' => $installation->id,
                'order_id' => $orderId,
            ]);
            $wasResolved = $openOrder->exists && $openOrder->resolved_at !== null;
            $attributes = [
                'kind' => $scheduled ? 'scheduled' : 'pending',
                'status' => $event->order_status,
                'resolved_at' => null,
            ];
            if (! $openOrder->exists || $wasResolved) {
                $attributes += [
                    'first_seen_at' => now(),
                    'last_alert_at' => now(),
                    'reminders_sent' => 0,
                ];
            }
            $openOrder->fill($attributes)->save();
        }

        SendOrderAlarm::dispatch(
            $installation->id,
            $scheduled ? 'scheduled' : 'order',
            $orderId,
            $event->order_status,
        );

        return $scheduled ? 'scheduled_alarm_dispatched' : 'alarm_dispatched';
    }

    private function updateOpenOrder(Installation $installation, WebhookEvent $event): void
    {
        $order = OpenOrder::where('installation_id', $installation->id)
            ->where('order_id', (string) $event->order_id)
            ->whereNull('resolved_at')
            ->first();
        if (! $order) {
            return;
        }

        $status = (string) $event->order_status;
        $pending = in_array($status, config('cardapioweb.pending_statuses'), true);
        $scheduled = in_array($status, config('cardapioweb.scheduled_statuses'), true);
        if (! $pending && ! $scheduled) {
            $order->forceFill(['status' => $status, 'resolved_at' => now()])->save();

            return;
        }

        $order->forceFill([
            'status' => $status,
            'kind' => $scheduled ? 'scheduled' : 'pending',
            'resolved_at' => null,
        ])->save();
    }
}
