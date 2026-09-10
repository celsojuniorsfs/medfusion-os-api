<?php

namespace Modules\Clients\Infrastructure\Projectors;

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
    }

    public function onClientRemoved(ClientRemoved $event): void
    {
        Client::whereKey($event->aggregateRootUuid())->delete();
    }
}
