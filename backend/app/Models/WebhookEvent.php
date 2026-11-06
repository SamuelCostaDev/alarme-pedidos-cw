<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'event_id', 'event_type', 'merchant_id', 'order_id', 'order_status', 'payload', 'result',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
