<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\UserAggregate;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Exceptions\DuplicateOrderNumberException;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Tests\TestCase;

/**
 * Testa OpenOrder resolvido pelo container (não a chamada direta ao agregado que
 * OrdersAggregateTest.php usa) — é aqui, e não no agregado, que a proteção contra número
 * duplicado vive (ver api-conventions.md § Concorrência na numeração da OS).
 */
class OrdersNumberingTest extends TestCase
{
    use RefreshDatabase;

    private function aClientId(): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: '31233218000110',
                tradeName: null,
                stateRegistration: null,
                requester: null,
                department: null,
                phone: null,
                email: null,
                address: null,
                city: null,
                state: null,
                postalCode: null,
            )
            ->persist();

        return $uuid;
    }

    private function aUserId(): string
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        return $uuid;
    }

    private function openOrder(int $number, string $clientId, string $userId): Order
    {
        return app(OpenOrder::class)(
            $number, '2026-09-08', $clientId, $userId,
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
    }

    public function test_opens_an_order_with_the_given_number(): void
    {
        $clientId = $this->aClientId();
        $userId = $this->aUserId();

        $order = $this->openOrder(1337, $clientId, $userId);

        $this->assertSame(1337, $order->number);
        $this->assertDatabaseHas('orders', ['number' => 1337, 'client_id' => $clientId]);
    }

    public function test_rejects_opening_a_second_order_with_the_same_number(): void
    {
        $clientId = $this->aClientId();
        $userId = $this->aUserId();

        $this->openOrder(1337, $clientId, $userId);

        $this->expectException(DuplicateOrderNumberException::class);

        try {
            $this->openOrder(1337, $clientId, $userId);
        } finally {
            // Nada corrompido: nem a segunda OS, nem um resquício de stored_events órfão da
            // tentativa que falhou dentro da transação.
            $this->assertSame(1, Order::count());
        }
    }
}
