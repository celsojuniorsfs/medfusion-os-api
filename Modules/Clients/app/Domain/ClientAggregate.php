<?php

namespace Modules\Clients\Domain;

use Modules\Clients\Domain\Events\ClientRegistered;
use Modules\Clients\Domain\Events\ClientRemoved;
use Modules\Clients\Domain\Events\ClientUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class ClientAggregate extends AggregateRoot
{
    private bool $removed = false;

    public function register(
        string $companyName,
        string $taxId,
        ?string $requester,
        ?string $department,
        ?string $phone,
        ?string $address,
        ?string $city,
        ?string $postalCode,
    ): self {
        $this->recordThat(new ClientRegistered(
            $companyName, $taxId, $requester, $department, $phone, $address, $city, $postalCode,
        ));

        return $this;
    }

    public function update(
        string $companyName,
        string $taxId,
        ?string $requester,
        ?string $department,
        ?string $phone,
        ?string $address,
        ?string $city,
        ?string $postalCode,
    ): self {
        $this->recordThat(new ClientUpdated(
            $companyName, $taxId, $requester, $department, $phone, $address, $city, $postalCode,
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
