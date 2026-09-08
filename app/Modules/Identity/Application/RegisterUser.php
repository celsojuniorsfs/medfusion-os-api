<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\UserAggregate;
use App\Modules\Identity\Infrastructure\ReadModels\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Action invocável: recebe dados já validados, comanda o agregado e devolve o read model.
 * Sem command bus — chamada direta (aqui, hoje, só pelo DatabaseSeeder; quando existir um
 * endpoint de cadastro de usuário, o controller chama esta mesma Action).
 */
class RegisterUser
{
    public function __invoke(string $name, string $email, string $password): User
    {
        $uuid = (string) Str::uuid();

        UserAggregate::retrieve($uuid)
            ->register($name, $email, Hash::make($password))
            ->persist();

        return User::findOrFail($uuid);
    }
}
