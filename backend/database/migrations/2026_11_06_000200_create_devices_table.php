<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Celular pareado com uma loja
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('platform')->default('android');
            $table->string('api_token_hash', 64)->unique(); // sha256 do token do aparelho
            $table->text('fcm_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->string('last_push_error')->nullable();
            $table->timestamps();
        });

        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('devices');
    }
};
