<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $fillable = [
        'code',
        'name',
        'secret',
        'callback_url',
        'callback_secret',
        'enabled',
    ];

    protected $hidden = [
        'secret',
        'callback_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(ProcessedCall::class);
    }
}
