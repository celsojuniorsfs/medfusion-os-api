<?php

namespace Modules\Clients\Domain;

use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Domain\Events\ClientRegistered;
use Modules\Clients\Domain\Events\ClientRemoved;
use Modules\Clients\Domain\Events\ClientUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class ClientAggregate extends AggregateRoot
{
    private bool $removed = false;

    public function register(
        PersonType $personType,
        string $name,
        string $taxId,
        ?string $tradeName,
        ?string $stateRegistration,
        ?string $requester,
        ?string $department,
        ?string $phone,
        ?string $email,
        ?string $address,
        ?string $city,
        ?string $state,
        ?string $postalCode,
    ): self {
        $this->recordThat(new ClientRegistered(
            $personType->value, $name, $taxId, $tradeName, $stateRegistration, $requester,
            $department, $phone, $email, $address, $city, $state, $postalCode,
        ));

        return $this;
    }

    public function update(
        PersonType $personType,
        string $name,
        string $taxId,
        ?string $tradeName,
        ?string $stateRegistration,
        ?string $requester,
        ?string $department,
        ?string $phone,
        ?string $email,
        ?string $address,
        ?string $city,
        ?string $state,
        ?string $postalCode,
    ): self {
        $this->recordThat(new ClientUpdated(
            $personType->value, $name, $taxId, $tradeName, $stateRegistration, $requester,
            $department, $phone, $email, $address, $city, $state, $postalCode,
        ));

        return $this;
    }

    public function remove(): self
    {
        if (! $this->removed) {
            $this->recordThat(new ClientRemoved);
        }

        return $this;
    }

    protected function applyClientRegistered(ClientRegistered $event): void {}

    protected function applyClientUpdated(ClientUpdated $event): void {}

    protected function applyClientRemoved(ClientRemoved $event): void
    {
        $this->removed = true;
    }
}
