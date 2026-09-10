<?php

namespace Modules\Clients\Application;

use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Infrastructure\ReadModels\Client;

class RegisterClient
{
    public function __invoke(
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
        $uuid = (string) Str::uuid();

        ClientAggregate::retrieve($uuid)
            ->register(
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

        return Client::findOrFail($uuid);
    }
}
