<?php

namespace Modules\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Application\RegisterUser;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;

class IdentityDatabaseSeeder extends Seeder
{
    /**
     * Usuário técnico inicial a partir de config('app.admin_*') — nunca hardcoded, conforme
     * decidido em docs/ambientes.md § Seed inicial de produção. Lido via config(), nunca env()
     * direto: o build roda `config:cache` antes do seed/migrate do deploy, e com config
     * cacheado o Laravel deixa de reler o .env (ver config/app.php). Passa pelo UserAggregate
     * (via a Action RegisterUser, ou changePassword num re-seed) em vez de User::create() — é a
     * mesma porta de entrada que qualquer outro caminho de cadastro de usuário vai usar.
     */
    public function run(): void
    {
        $email = config('app.admin_email');
        $password = config('app.admin_password');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL/ADMIN_PASSWORD não definidos — seeder de admin pulado.');

            return;
        }

        $existing = User::where('email', $email)->first();

        if (! $existing) {
            (new RegisterUser)(config('app.admin_name'), $email, $password, UserRole::GeneralAdmin);

            return;
        }

        if (! Hash::check($password, $existing->password)) {
            UserAggregate::retrieve($existing->id)
                ->changePassword(Hash::make($password))
                ->persist();
        }
    }
}
