<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform');
            $table->string('strategy');
            $table->boolean('active')->default(false);
            $table->boolean('live')->default(false);
            $table->foreignId('platform_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('settings');
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
