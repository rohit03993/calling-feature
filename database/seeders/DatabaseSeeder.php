<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $code = (string) config('call_ai.school_code');
        $secret = (string) config('call_ai.school_secret');

        if ($code === '' || $secret === '') {
            return;
        }

        School::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'secret' => $secret,
                'callback_url' => config('call_ai.school_callback_url'),
                'callback_secret' => config('call_ai.school_callback_secret'),
                'enabled' => true,
            ],
        );
    }
}
