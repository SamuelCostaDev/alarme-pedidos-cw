<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Autentica o celular pelo token recebido no pareamento (Authorization: Bearer ...). */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = Device::findByApiToken($request->bearerToken());

        if (! $device || ! $device->installation?->isActive()) {
            return response()->json(['error' => 'device_not_paired'], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
