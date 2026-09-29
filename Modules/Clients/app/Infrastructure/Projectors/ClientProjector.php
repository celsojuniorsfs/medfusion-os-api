<?php

namespace Modules\Clients\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Clients\Domain\Events\ClientRegistered;
use Modules\Clients\Domain\Events\ClientRemoved;
use Modules\Clients\Domain\Events\ClientUpdated;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class ClientProjector extends Projector
{
    public function onClientRegistered(ClientRegistered $event): void
    {
        Client::create([
            'id' => $event->aggregateRootUuid(),
            'person_type' => $event->personType,
            'name' => $event->name,
            'trade_name' => $event->tradeName,
            'tax_id' => $event->taxId,
            'state_registration' => $event->stateRegistration,
            'requester' => $event->requester,
            'department' => $event->department,
            'phone' => $event->phone,
            'email' => $event->email,
            'address' => $event->address,
            'city' => $event->city,
            'state' => $event->state,
            'postal_code' => $event->postalCode,
        ]);

        $this->forgetCache();
    }

    public function onClientUpdated(ClientUpdated $event): void
    {
        Client::whereKey($event->aggregateRootUuid())->update([
            'person_type' => $event->personType,
            'name' => $event->name,
            'trade_name' => $event->tradeName,
            'tax_id' => $event->taxId,
            'state_registration' => $event->stateRegistration,
            'requester' => $event->requester,
            'department' => $event->department,
            'phone' => $event->phone,
            'email' => $event->email,
            'address' => $event->address,
            'city' => $event->city,
            'state' => $event->state,
            'postal_code' => $event->postalCode,
        ]);

        $this->forgetCache();
    }

    public function onClientRemoved(ClientRemoved $event): void
    {
        Client::whereKey($event->aggregateRootUuid())->delete();

        $this->forgetCache();
    }

    /**
     * Invalida o cache incrementando um contador de versão, não `Cache::tags()->flush()`
     * (instável em Redis/Valkey gerenciado com réplica/cluster — achado em produção).
     */
    private function forgetCache(): void
    {
        Cache::increment('clients:cache-version');
    }

    /**
     * Chamado pelo spatie antes de um replay, para limpar a tabela sem estourar `tax_id`
     * duplicado. FKs desligadas porque `equipments`/`orders` apontam pra `clients` mas pertencem
     * a outros projectors. Com `--aggregate-uuid=X` (replay de um agregado só), $aggregateUuid
     * vem preenchido e só aquela linha é apagada — nunca a tabela inteira.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            Client::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });

        $this->forgetCache();
    }
}
