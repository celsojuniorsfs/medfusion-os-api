<?php

namespace App\Modules\Identity\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserPasswordChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $password,
    ) {}
}
