<?php

namespace Modules\Identity\Infrastructure\Projectors;

use Illuminate\Support\Facades\Schema;
use Modules\Identity\Domain\Enums\UserRole;
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
            // Eventos gravados antes do api#133 não têm role no payload — trata quem já
            // existia como technician (o papel mais restrito, nunca recebe alerta sozinho).
            'role' => $event->role ?? UserRole::Technician->value,
        ]);
    }

    public function onUserPasswordChanged(UserPasswordChanged $event): void
    {
        User::whereKey($event->aggregateRootUuid())->update([
            'password' => $event->password,
        ]);
    }

    /**
     * Chamado pelo spatie antes de um replay (ver Projectionist::replay) — sem isso,
     * `onUserRegistered` estoura `UniqueConstraintViolationException` ao recriar uma linha que já
     * existe. FKs desligadas porque `orders.user_id` (restrictOnDelete) pertence a Orders, e
     * replayar só este projector não pode falhar por causa de outro módulo. `personal_access_tokens`
     * não é tocado: sem FK pra `users`, e os uuids voltam idênticos, então os tokens continuam
     * válidos.
     *
     * $aggregateUuid vem preenchido com `--aggregate-uuid=X` (replay de um agregado só); o spatie
     * sempre passa isso, então só apaga a tabela inteira quando for null (replay completo de
     * verdade).
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            User::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });
    }
}
