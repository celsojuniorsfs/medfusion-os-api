<?php

namespace Modules\Clients\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
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
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) incrementando um contador
     * de versão — não `Cache::tags()->flush()` (achado em produção: operação multi-chave, fonte
     * conhecida de comportamento inconsistente em Redis/Valkey gerenciado com réplica/cluster).
     * `increment()` é uma única chave, funciona igual em qualquer topologia. O Projector já é o
     * único lugar que escreve no read model, então vira também o único lugar que invalida o
     * cache dele.
     */
    private function forgetCache(): void
    {
        Cache::increment('clients:cache-version');
    }
}
