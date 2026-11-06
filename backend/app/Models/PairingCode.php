<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PairingCode extends Model
{
    protected $fillable = ['installation_id', 'code', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** Código de 6 dígitos, fácil de digitar no celular. */
    public static function generateFor(Installation $installation): self
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());

        return static::create([
            'installation_id' => $installation->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(config('cardapioweb.pairing_code_ttl_minutes')),
        ]);
    }
}
