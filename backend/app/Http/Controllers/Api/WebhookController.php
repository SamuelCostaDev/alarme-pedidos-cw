<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOrderAlarm;
use App\Models\Installation;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Webhook de pedidos da Cardápio Web.
 * Precisa responder 200 em até 5 s — todo o envio de push vai pra fila.
 *
 * @see https://docs.cardapioweb.com/webhooks/visao-geral.md
 * @see https://docs.cardapioweb.com/webhooks/eventos.md
 */
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
            // 200 mesmo assim: não adianta a CW ficar reenviando um payload que não entendemos
            return response()->json(['ok' => true, 'ignored' => 'invalid_payload']);
        }

        try {
            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $type,
                'merchant_id' => $merchantId,
                'order_id' => (string) $request->input('order_id'),
                'order_status' => (string) $request->input('order_status'),
                'payload' => $request->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Retentativa de um evento que já recebemos
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $event->update(['result' => $this->route($event)]);

        return response()->json(['ok' => true]);
    }

    private function route(WebhookEvent $event): string
    {
        if ($event->event_type !== 'ORDER_CREATED') {
            return 'ignored_type';
        }

        if (in_array($event->order_status, config('cardapioweb.ignored_statuses'), true)) {
            return 'ignored_status';
        }

        $installation = Installation::where('company_external_id', $event->merchant_id)
            ->where('status', 'active')
            ->first();
        if (! $installation) {
            return 'unknown_merchant';
        }

        SendOrderAlarm::dispatch($installation->id, 'order', $event->order_id, $event->order_status);

        return 'alarm_dispatched';
    }
}
