<?php

namespace App\Modules\Identity\Infrastructure\Projectors;

use App\Modules\Identity\Domain\Events\UserPasswordChanged;
use App\Modules\Identity\Domain\Events\UserRegistered;
use App\Modules\Identity\Infrastructure\ReadModels\User;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class UserProjector extends Projector
{
    public function onUserRegistered(UserRegistered $event): void
    {
        User::create([
            'id' => $event->aggregateRootUuid(),
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->password,
        ]);
    }

    public function onUserPasswordChanged(UserPasswordChanged $event): void
    {
        User::whereKey($event->aggregateRootUuid())->update([
            'password' => $event->password,
        ]);
    }
}
