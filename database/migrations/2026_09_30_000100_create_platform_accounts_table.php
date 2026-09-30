<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A connected account on a platform, e.g. one Facebook Page. Tokens are stored encrypted.
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('name');
            $table->string('external_id');
            $table->text('credentials')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
