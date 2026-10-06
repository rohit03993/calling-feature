<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallProcessingJob extends Model
{
    protected $fillable = [
        'call_id',
        'job_type',
        'status',
        'attempts',
        'max_attempts',
        'started_at',
        'completed_at',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ProcessedCall::class, 'call_id');
    }
}
