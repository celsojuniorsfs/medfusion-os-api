<?php

namespace App\Modules\Clients\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ClientUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $taxId,
        public readonly ?string $requester,
        public readonly ?string $department,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $postalCode,
    ) {}
}
