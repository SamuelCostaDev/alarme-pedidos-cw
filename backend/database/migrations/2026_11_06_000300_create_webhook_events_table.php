<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro dos webhooks recebidos — também serve pra descartar retentativas duplicadas
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('merchant_id')->index();
            $table->string('order_id')->nullable();
            $table->string('order_status')->nullable();
            $table->json('payload');
            $table->string('result')->nullable(); // alarm_dispatched | ignored_status | unknown_merchant | ignored_type
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
