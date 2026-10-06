<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('secret');
            $table->string('callback_url')->nullable();
            $table->string('callback_secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('phone_number', 20)->nullable();
            $table->string('call_direction', 20)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('original_storage_key')->nullable();
            $table->string('processing_storage_key')->nullable();
            $table->string('audio_mime_type')->nullable();
            $table->unsignedBigInteger('audio_size')->nullable();
            $table->string('audio_checksum', 64)->nullable();
            $table->string('processing_status', 40);
            $table->string('language', 20)->nullable();
            $table->string('transcription_model')->nullable();
            $table->string('ai_model')->nullable();
            $table->longText('raw_transcript')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->json('transcript_json')->nullable();
            $table->text('summary')->nullable();
            $table->text('short_summary')->nullable();
            $table->json('ai_analysis_json')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'processing_status']);
        });

        Schema::create('call_transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('start_seconds', 10, 2);
            $table->decimal('end_seconds', 10, 2);
            $table->string('speaker', 40);
            $table->text('text');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['call_id', 'sequence']);
        });

        Schema::create('call_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('job_type', 40);
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['call_id', 'job_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_processing_jobs');
        Schema::dropIfExists('call_transcript_segments');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('schools');
    }
};
