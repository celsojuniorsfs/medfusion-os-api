<?php

namespace Modules\Clients\Application;

use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Infrastructure\ReadModels\Client;

class UpdateClient
{
    public function __invoke(
        string $id,
        string $companyName,
        string $taxId,
        ?string $requester = null,
        ?string $department = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $city = null,
        ?string $postalCode = null,
    ): Client {
        ClientAggregate::retrieve($id)
            ->update($companyName, $taxId, $requester, $department, $phone, $address, $city, $postalCode)
            ->persist();

        return Client::findOrFail($id);
    }
}
