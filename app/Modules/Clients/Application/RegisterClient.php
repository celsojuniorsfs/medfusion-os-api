<?php

namespace App\Modules\Clients\Application;

use App\Modules\Clients\Domain\ClientAggregate;
use App\Modules\Clients\Infrastructure\ReadModels\Client;
use Illuminate\Support\Str;

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
