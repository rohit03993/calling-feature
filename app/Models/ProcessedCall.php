<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessedCall extends Model
{
    protected $table = 'calls';

    protected $fillable = [
        'school_id',
        'public_id',
        'phone_number',
        'call_direction',
        'recorded_at',
        'duration_seconds',
        'original_storage_key',
        'processing_storage_key',
        'audio_mime_type',
        'audio_size',
        'audio_checksum',
        'processing_status',
        'language',
        'transcription_model',
        'ai_model',
        'raw_transcript',
        'transcript_text',
        'transcript_json',
        'summary',
        'short_summary',
        'ai_analysis_json',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'transcript_json' => 'array',
            'ai_analysis_json' => 'array',
            'duration_seconds' => 'integer',
            'audio_size' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(CallTranscriptSegment::class, 'call_id')->orderBy('sequence');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CallProcessingJob::class, 'call_id');
    }
}
