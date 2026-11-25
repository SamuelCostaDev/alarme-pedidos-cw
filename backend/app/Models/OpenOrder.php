<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenOrder extends Model
{
    protected $fillable = [
        'installation_id', 'order_id', 'kind', 'status', 'first_seen_at',
        'last_alert_at', 'reminders_sent', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_alert_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reminders_sent' => 'integer',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
