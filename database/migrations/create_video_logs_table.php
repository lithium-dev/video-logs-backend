<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('user_id')->nullable();
            $table->nullableMorphs('loggable');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider')->default('s3');
            $table->string('source_key')->nullable();
            $table->string('playback_key')->nullable();
            $table->string('playback_url')->nullable();
            $table->string('poster_key')->nullable();
            $table->string('poster_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->string('playback_format')->default('mp4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_logs');
    }
};
