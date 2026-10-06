<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallTranscriptSegment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'call_id',
        'sequence',
        'start_seconds',
        'end_seconds',
        'speaker',
        'text',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'start_seconds' => 'float',
            'end_seconds' => 'float',
            'confidence' => 'float',
            'sequence' => 'integer',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ProcessedCall::class, 'call_id');
    }
}
