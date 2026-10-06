<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        School::syncFromConfig();
    }
}
