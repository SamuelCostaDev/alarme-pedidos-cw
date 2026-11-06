<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma instalação do app em uma loja da Cardápio Web
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->string('company_external_id')->unique(); // company.id / merchant_id na CW
            $table->string('company_name')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('owner_external_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scope')->nullable();
            $table->string('status')->default('active'); // active | revoked
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
