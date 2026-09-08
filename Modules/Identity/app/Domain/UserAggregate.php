<?php

namespace Modules\Identity\Domain;

use Modules\Identity\Domain\Events\UserPasswordChanged;
use Modules\Identity\Domain\Events\UserRegistered;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class UserAggregate extends AggregateRoot
{
    public function register(string $name, string $email, string $hashedPassword): self
    {
        $this->recordThat(new UserRegistered($name, $email, $hashedPassword));

        return $this;
    }

    public function changePassword(string $hashedPassword): self
    {
        $this->recordThat(new UserPasswordChanged($hashedPassword));

        return $this;
    }

    protected function applyUserRegistered(UserRegistered $event): void
    {
        // Sem estado interno necessário no agregado — nada aqui condiciona comandos futuros.
    }

    protected function applyUserPasswordChanged(UserPasswordChanged $event): void
    {
        //
    }
}
