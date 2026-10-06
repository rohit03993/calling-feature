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

    public static function syncFromConfig(): void
    {
        $code = (string) config('call_ai.school_code');
        $secret = (string) config('call_ai.school_secret');

        if ($code === '' || $secret === '') {
            return;
        }

        $callbackUrl = (string) config('call_ai.school_callback_url');
        $callbackSecret = (string) config('call_ai.school_callback_secret');

        static::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'secret' => $secret,
                'callback_url' => $callbackUrl !== '' ? $callbackUrl : null,
                'callback_secret' => $callbackSecret !== '' ? $callbackSecret : null,
                'enabled' => true,
            ],
        );
    }
}
