<?php

namespace Modules\Clients\Application;

use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Infrastructure\ReadModels\Client;

class RegisterClient
{
    public function __invoke(
        string $companyName,
        string $taxId,
        ?string $requester = null,
        ?string $department = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $city = null,
        ?string $postalCode = null,
    ): Client {
        $uuid = (string) Str::uuid();

        ClientAggregate::retrieve($uuid)
            ->register($companyName, $taxId, $requester, $department, $phone, $address, $city, $postalCode)
            ->persist();

        return Client::findOrFail($uuid);
    }
}
