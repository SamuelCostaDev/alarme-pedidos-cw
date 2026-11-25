<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('order_id');
            $table->string('kind'); // pending | scheduled
            $table->string('status');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_alert_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'order_id']);
            $table->index(['kind', 'resolved_at', 'last_alert_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_orders');
    }
};
