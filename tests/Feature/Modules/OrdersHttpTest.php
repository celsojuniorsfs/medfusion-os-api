<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\OpenOrder;
use Tests\TestCase;

class OrdersHttpTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        return User::findOrFail($uuid);
    }

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

    private function openOrder(int $number, string $clientId, string $userId): void
    {
        app(OpenOrder::class)(
            $number, '2026-09-08', $clientId, $userId,
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
    }

    public function test_guests_cannot_access_the_next_number_endpoint(): void
    {
        $this->getJson('/api/v1/orders/next-number')->assertStatus(401);
    }

    public function test_suggests_1337_when_the_orders_table_is_empty(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1337]);
    }

    public function test_suggests_the_last_number_plus_one_after_an_order_is_opened(): void
    {
        $user = $this->authenticatedUser();
        $this->openOrder(1400, $this->aClientId(), $user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1401]);
    }

    public function test_a_gap_left_by_an_overridden_number_stays_free(): void
    {
        // Se o técnico sobrescreveu a sugestão pra um número bem maior, os números pulados ficam
        // permanentemente livres — next-number nunca "preenche" a lacuna, só reflete o MAX() atual
        // (ver api-conventions.md § Concorrência na numeração da OS).
        $user = $this->authenticatedUser();
        $this->openOrder(1500, $this->aClientId(), $user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1501]);
    }
}
