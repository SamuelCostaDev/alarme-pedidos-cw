<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    protected $fillable = [
        'company_external_id', 'company_name', 'owner_name', 'owner_email', 'owner_external_id',
        'access_token', 'refresh_token', 'token_expires_at', 'scope', 'status',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            // Tokens da CW ficam criptografados no banco (APP_KEY)
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function pairingCodes(): HasMany
    {
        return $this->hasMany(PairingCode::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
