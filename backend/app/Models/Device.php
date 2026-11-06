<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $fillable = [
        'installation_id', 'name', 'platform', 'api_token_hash', 'fcm_token',
        'last_seen_at', 'last_push_at', 'last_push_error',
    ];

    protected $hidden = ['api_token_hash', 'fcm_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_push_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /** Gera um token novo pro aparelho. Só o hash fica no banco. */
    public static function newApiToken(): array
    {
        $plain = Str::random(64);

        return [$plain, hash('sha256', $plain)];
    }

    public static function findByApiToken(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::where('api_token_hash', hash('sha256', $plain))->first();
    }
}
