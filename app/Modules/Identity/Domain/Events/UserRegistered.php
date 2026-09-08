<?php

namespace App\Modules\Identity\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * password já vem hasheado (App\Modules\Identity\Application\RegisterUser) — o domínio nunca
 * lida com senha em texto puro, mesmo guardada em stored_events.
 */
class UserRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}
