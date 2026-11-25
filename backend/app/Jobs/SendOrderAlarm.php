<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Installation;
use App\Services\FcmSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendOrderAlarm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public function __construct(
        public int $installationId,
        public string $kind, // order | test | pending | scheduled
        public ?string $orderId = null,
        public ?string $orderStatus = null,
        public ?int $onlyDeviceId = null,
    ) {}

    public function handle(FcmSender $fcm): void
    {
        $installation = Installation::find($this->installationId);
        if (! $installation || ! $installation->isActive()) {
            return;
        }

        $devices = $installation->devices()
            ->whereNotNull('fcm_token')
            ->when($this->onlyDeviceId, fn ($q) => $q->whereKey($this->onlyDeviceId))
            ->get();

        [$title, $body] = match ($this->kind) {
            'test' => ['Teste do alarme', 'Se está tocando, o alarme da '.($installation->company_name ?? 'loja').' funciona.'],
            'pending' => ['Pedido PENDENTE', 'O pedido #'.$this->orderId.' ainda aguarda confirmação.'],
            'scheduled' => ['Pedido AGENDADO', 'Pedido #'.$this->orderId.' agendado na '.($installation->company_name ?? 'loja').'.'],
            default => ['Pedido novo!', 'Pedido #'.$this->orderId.' chegou na '.($installation->company_name ?? 'loja').'.'],
        };

        $data = [
            'type' => $this->kind,
            'order_id' => $this->orderId ?? '',
            'order_status' => $this->orderStatus ?? '',
            'company_name' => $installation->company_name ?? '',
            'title' => $title,
            'body' => $body,
            'sent_at' => now()->toIso8601String(),
        ];

        $failures = 0;
        foreach ($devices as $device) {
            /** @var Device $device */
            $res = $fcm->send($device->fcm_token, $data);
            if ($res['result'] === FcmSender::RESULT_OK) {
                $device->forceFill(['last_push_at' => now(), 'last_push_error' => null])->save();
            } elseif ($res['result'] === FcmSender::RESULT_INVALID_TOKEN) {
                $device->forceFill(['fcm_token' => null, 'last_push_error' => $res['detail']])->save();
            } else {
                $failures++;
                $device->forceFill(['last_push_error' => $res['detail']])->save();
                Log::warning('Falha no push do alarme', [
                    'device_id' => $device->id, 'detail' => $res['detail'],
                ]);
            }
        }

        if ($failures > 0 && $failures === $devices->count()) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
