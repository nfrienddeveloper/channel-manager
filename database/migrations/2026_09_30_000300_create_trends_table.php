<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One trending story, merged across sources. `fingerprint` identifies the story for de-duplication.
        Schema::create('trends', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->default('US');
            $table->string('fingerprint');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('sources');
            $table->json('articles');
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('traffic')->default(0);
            $table->float('score')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['region', 'fingerprint']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trends');
    }
};
