<?php

namespace Modules\Clients\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ClientRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $personType,
        public readonly string $name,
        public readonly string $taxId,
        public readonly ?string $tradeName,
        public readonly ?string $stateRegistration,
        public readonly ?string $requester,
        public readonly ?string $department,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
    ) {}
}
