<?php

namespace App\Services;

use App\Jobs\SendOrderAlarm;
use App\Models\OpenOrder;
use Illuminate\Support\Facades\DB;

class PendingOrderReminderDispatcher
{
    public function run(): int
    {
        $now = now();
        OpenOrder::query()
            ->whereNull('resolved_at')
            ->where('first_seen_at', '<=', $now->copy()->subHours(6))
            ->update(['resolved_at' => $now, 'updated_at' => $now]);

        $after = (int) config('cardapioweb.pending_reminder_after_minutes', 3);
        $every = (int) config('cardapioweb.pending_reminder_every_minutes', 3);
        $max = (int) config('cardapioweb.pending_reminder_max', 5);
        $pendingStatuses = config('cardapioweb.pending_statuses', ['waiting_confirmation']);
        $sent = 0;

        OpenOrder::query()
            ->with('installation')
            ->where('kind', 'pending')
            ->whereIn('status', $pendingStatuses)
            ->whereNull('resolved_at')
            ->where('first_seen_at', '<=', $now->copy()->subMinutes($after))
            ->where(function ($query) use ($now, $every) {
                $query->whereNull('last_alert_at')
                    ->orWhere('last_alert_at', '<=', $now->copy()->subMinutes($every));
            })
            ->where('reminders_sent', '<', $max)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$sent, $now, $max, $after, $every, $pendingStatuses) {
                foreach ($orders as $candidate) {
                    DB::transaction(function () use ($candidate, &$sent, $now, $max, $after, $every, $pendingStatuses) {
                        $order = OpenOrder::query()->lockForUpdate()->find($candidate->id);
                        if (! $order || $order->resolved_at || $order->reminders_sent >= $max
                            || $order->kind !== 'pending'
                            || ! in_array($order->status, $pendingStatuses, true)
                            || $order->first_seen_at->gt($now->copy()->subMinutes($after))
                            || ($order->last_alert_at && $order->last_alert_at->gt($now->copy()->subMinutes($every)))) {
                            return;
                        }

                        $installation = $order->installation;
                        if (! $installation || ! $installation->isActive()) {
                            return;
                        }

                        SendOrderAlarm::dispatch(
                            $installation->id,
                            'pending',
                            $order->order_id,
                            $order->status,
                        );
                        $order->forceFill([
                            'last_alert_at' => $now,
                            'reminders_sent' => $order->reminders_sent + 1,
                        ])->save();
                        $sent++;
                    });
                }
            });

        return $sent;
    }
}
