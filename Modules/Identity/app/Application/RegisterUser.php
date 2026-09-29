<?php

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;

/**
 * Action invocável: recebe dados já validados, comanda o agregado e devolve o read model.
 */
class RegisterUser
{
    public function __invoke(string $name, string $email, string $password, UserRole $role = UserRole::Technician): User
    {
        $uuid = (string) Str::uuid();

        UserAggregate::retrieve($uuid)
            ->register($name, $email, Hash::make($password), $role)
            ->persist();

        return User::findOrFail($uuid);
    }
}
