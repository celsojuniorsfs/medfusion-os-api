<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Usuário técnico inicial a partir de variáveis de ambiente (ADMIN_*) — nunca hardcoded,
     * conforme decidido em docs/ambientes.md § Seed inicial de produção.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL/ADMIN_PASSWORD não definidos no .env — seeder de admin pulado.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrador'),
                'password' => $password,
            ]
        );
    }
}
