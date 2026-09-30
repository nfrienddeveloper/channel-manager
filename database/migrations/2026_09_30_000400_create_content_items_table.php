<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One video for one channel, from idea to published post.
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trend_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->index();
            $table->string('topic');
            $table->string('category')->nullable();
            $table->json('research')->nullable();
            $table->json('storyboard')->nullable();
            $table->text('caption')->nullable();
            $table->json('sources')->nullable();
            $table->json('credits')->nullable();
            $table->string('video_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->float('seconds')->nullable();
            $table->string('voice_engine')->nullable();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->string('external_id')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('channel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 16)->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_events');
        Schema::dropIfExists('content_items');
    }
};
