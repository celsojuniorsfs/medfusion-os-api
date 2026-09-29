<?php

namespace Modules\Identity\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * password já vem hasheado (Modules\Identity\Application\RegisterUser) — o domínio nunca lida
 * com senha em texto puro.
 *
 * role é `?string = null` (guarda `UserRole::value`, nunca a instância) e vem por último —
 * eventos antigos (pré-api#133) não têm essa chave no payload (ver CLAUDE.md § "Acrescentar
 * campo a um evento já gravado"); o projector decide o default pra quem já existia.
 */
class UserRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $role = null,
    ) {}
}
