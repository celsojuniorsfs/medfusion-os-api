<?php

namespace App\Modules\Clients\Infrastructure\Projectors;

use App\Modules\Clients\Domain\Events\ClientRegistered;
use App\Modules\Clients\Domain\Events\ClientRemoved;
use App\Modules\Clients\Domain\Events\ClientUpdated;
use App\Modules\Clients\Infrastructure\ReadModels\Client;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class ClientProjector extends Projector
{
    public function onClientRegistered(ClientRegistered $event): void
    {
        Client::create([
            'id' => $event->aggregateRootUuid(),
            'company_name' => $event->companyName,
            'tax_id' => $event->taxId,
            'requester' => $event->requester,
            'department' => $event->department,
            'phone' => $event->phone,
            'address' => $event->address,
            'city' => $event->city,
            'postal_code' => $event->postalCode,
        ]);
    }

    public function onClientUpdated(ClientUpdated $event): void
    {
        Client::whereKey($event->aggregateRootUuid())->update([
            'company_name' => $event->companyName,
            'tax_id' => $event->taxId,
            'requester' => $event->requester,
            'department' => $event->department,
            'phone' => $event->phone,
            'address' => $event->address,
            'city' => $event->city,
            'postal_code' => $event->postalCode,
        ]);
    }

    public function onClientRemoved(ClientRemoved $event): void
    {
        Client::whereKey($event->aggregateRootUuid())->delete();
    }
}
