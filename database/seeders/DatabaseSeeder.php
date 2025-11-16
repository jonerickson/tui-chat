<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Room::factory()->state([
            'name' => 'General',
            'slug' => 'general',
        ])->create();
    }
}
