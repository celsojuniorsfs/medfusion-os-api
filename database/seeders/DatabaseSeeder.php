<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Database\Seeders\IdentityDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Cada módulo com dados iniciais tem seu próprio seeder
     * (Modules/<Nome>/database/seeders/) — este arquivo só orquestra.
     */
    public function run(): void
    {
        $this->call([
            IdentityDatabaseSeeder::class,
        ]);
    }
}
