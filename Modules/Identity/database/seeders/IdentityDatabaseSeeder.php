<?php

namespace Modules\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Application\RegisterUser;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;

class IdentityDatabaseSeeder extends Seeder
{
    /**
     * Usuário técnico inicial a partir de variáveis de ambiente (ADMIN_*) — nunca hardcoded,
     * conforme decidido em docs/ambientes.md § Seed inicial de produção. Passa pelo
     * UserAggregate (via a Action RegisterUser, ou changePassword num re-seed) em vez de
     * User::create() — é a mesma porta de entrada que qualquer outro caminho de cadastro de
     * usuário vai usar.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL/ADMIN_PASSWORD não definidos no .env — seeder de admin pulado.');

            return;
        }

        $existing = User::where('email', $email)->first();

        if (! $existing) {
            (new RegisterUser)(env('ADMIN_NAME', 'Administrador'), $email, $password);

            return;
        }

        if (! Hash::check($password, $existing->password)) {
            UserAggregate::retrieve($existing->id)
                ->changePassword(Hash::make($password))
                ->persist();
        }
    }
}
