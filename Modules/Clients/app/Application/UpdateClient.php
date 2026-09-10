<?php

namespace Modules\Clients\Application;

use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Infrastructure\ReadModels\Client;

class UpdateClient
{
    public function __invoke(
        string $id,
        PersonType $personType,
        string $name,
        string $taxId,
        ?string $tradeName = null,
        ?string $stateRegistration = null,
        ?string $requester = null,
        ?string $department = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $address = null,
        ?string $city = null,
        ?string $state = null,
        ?string $postalCode = null,
    ): Client {
        ClientAggregate::retrieve($id)
            ->update(
                personType: $personType,
                name: $name,
                taxId: $taxId,
                tradeName: $tradeName,
                stateRegistration: $stateRegistration,
                requester: $requester,
                department: $department,
                phone: $phone,
                email: $email,
                address: $address,
                city: $city,
                state: $state,
                postalCode: $postalCode,
            )
            ->persist();

        return Client::findOrFail($id);
    }
}
