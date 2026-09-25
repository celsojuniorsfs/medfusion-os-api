<?php

namespace Modules\Identity\Infrastructure\Projectors;

use Illuminate\Support\Facades\Schema;
use Modules\Identity\Domain\Events\UserPasswordChanged;
use Modules\Identity\Domain\Events\UserRegistered;
use Modules\Identity\Infrastructure\ReadModels\User;
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

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0` (ver Projectionist::replay)
     * — sem isso, `onUserRegistered` estoura `UniqueConstraintViolationException` ao tentar
     * recriar uma linha que já existe. FKs desligadas: `orders.user_id` (restrictOnDelete)
     * pertence a Orders, e replayar só este projector não pode falhar por causa de outro módulo.
     * `personal_access_tokens` não é tocado: não tem FK pra `users` (só um `uuidMorphs`), e os
     * uuids voltam idênticos, então os tokens continuam válidos depois do replay.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(fn () => User::query()->delete());
    }
}
