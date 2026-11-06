<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOrderAlarm;
use App\Models\Device;
use App\Models\PairingCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** API usada pelo app Android. */
class DeviceController extends Controller
{
    /** Troca o código de 6 dígitos do painel por um token do aparelho. */
    public function pair(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'fcm_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios'],
        ]);

        return DB::transaction(function () use ($data) {
            $code = PairingCode::where('code', preg_replace('/\D/', '', $data['code']))
                ->lockForUpdate()
                ->first();

            if (! $code || ! $code->isUsable() || ! $code->installation->isActive()) {
                return response()->json(['error' => 'invalid_code', 'message' => 'Código inválido ou expirado. Gere outro no painel.'], 422);
            }

            [$plain, $hash] = Device::newApiToken();

            // Mesmo celular pareando de novo: substitui o registro antigo daquele token FCM
            Device::where('fcm_token', $data['fcm_token'])->delete();

            $device = $code->installation->devices()->create([
                'name' => $data['device_name'] ?? 'Celular',
                'platform' => $data['platform'] ?? 'android',
                'api_token_hash' => $hash,
                'fcm_token' => $data['fcm_token'],
                'last_seen_at' => now(),
            ]);
            $code->update(['used_at' => now()]);

            return response()->json([
                'device_token' => $plain,
                'device_id' => $device->id,
                'company_name' => $code->installation->company_name,
            ], 201);
        });
    }

    public function me(Request $request)
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return response()->json([
            'device_id' => $device->id,
            'name' => $device->name,
            'company_name' => $device->installation->company_name,
            'has_fcm_token' => $device->fcm_token !== null,
            'last_push_at' => $device->last_push_at?->toIso8601String(),
        ]);
    }

    public function updateFcmToken(Request $request)
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:4096']]);
        $request->attributes->get('device')->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->json(['ok' => true]);
    }

    /** Teste ponta a ponta: servidor → FCM → este celular. */
    public function test(Request $request)
    {
        $device = $request->attributes->get('device');
        SendOrderAlarm::dispatch($device->installation_id, 'test', onlyDeviceId: $device->id);

        return response()->json(['ok' => true]);
    }

    public function unpair(Request $request)
    {
        $request->attributes->get('device')->delete();

        return response()->json(['ok' => true]);
    }
}
