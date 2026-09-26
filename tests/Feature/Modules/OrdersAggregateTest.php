<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\UserAggregate;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Tests\TestCase;

class OrdersAggregateTest extends TestCase
{
    use RefreshDatabase;

    private function aClientId(string $taxId = '31233218000110'): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: $taxId,
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

    public function test_opening_an_order_attaching_equipment_and_adding_items_projects_the_total(): void
    {
        $orderUuid = (string) Str::uuid();

        OrderAggregate::retrieve($orderUuid)
            ->open(
                1337, '2026-09-08', $this->aClientId(), $this->aUserId(),
                pickedUp: false, warranty: false, technicalTraining: false, onSiteQuote: false, rental: false,
                reportedDefect: 'Não liga', maintenancePlan: null, notes: null,
                paymentMethod: null, warrantyPeriod: null, proposalValidity: null, laborCost: 150.0,
            )
            ->attachEquipment((string) Str::uuid(), null, 'Bisturi', 'Marca X', null, 'SN-123', null, [])
            ->addItem(2, 'Peça de reposição', 25.0)
            ->persist();

        $order = Order::with(['equipments', 'items'])->findOrFail($orderUuid);

        $this->assertSame('open', $order->status);
        $this->assertSame(1, $order->equipments->count());
        $this->assertSame(1, $order->items->count());
        // total = labor_cost (150) + quantity (2) * unit_price (25) = 200
        $this->assertEqualsWithDelta(200.0, (float) $order->total, 0.001);
    }

    public function test_valid_status_transition_is_projected(): void
    {
        $orderUuid = (string) Str::uuid();
        OrderAggregate::retrieve($orderUuid)
            ->open(1338, '2026-09-08', $this->aClientId(), $this->aUserId(), false, false, false, false, false, null, null, null, null, null, null, null)
            ->persist();

        OrderAggregate::retrieve($orderUuid)->changeStatus(OrderStatus::InAnalysis)->persist();

        $this->assertSame('in_analysis', Order::findOrFail($orderUuid)->status);
    }

    public function test_invalid_status_transition_is_rejected_by_the_aggregate(): void
    {
        $orderUuid = (string) Str::uuid();
        OrderAggregate::retrieve($orderUuid)
            ->open(1339, '2026-09-08', $this->aClientId(), $this->aUserId(), false, false, false, false, false, null, null, null, null, null, null, null)
            ->persist();

        $this->expectException(InvalidOrderStatusTransition::class);

        // open → completed não está na tabela de transições (api-conventions.md § Status da OS).
        OrderAggregate::retrieve($orderUuid)->changeStatus(OrderStatus::Completed);
    }

    public function test_update_changes_header_fields_and_recalculates_total(): void
    {
        $orderUuid = (string) Str::uuid();
        OrderAggregate::retrieve($orderUuid)
            ->open(1340, '2026-09-08', $this->aClientId(), $this->aUserId(), false, false, false, false, false, null, null, null, null, null, null, 100.0)
            ->persist();

        $newClientId = $this->aClientId('11222333000181');
        OrderAggregate::retrieve($orderUuid)
            ->update(1340, '2026-09-10', $newClientId, true, false, false, false, false, null, null, 'Nota nova', null, null, null, 200.0)
            ->persist();

        $order = Order::findOrFail($orderUuid);
        $this->assertSame('2026-09-10', $order->date->toDateString());
        $this->assertSame($newClientId, $order->client_id);
        $this->assertTrue((bool) $order->picked_up);
        $this->assertSame('Nota nova', $order->notes);
        // Sem itens ainda — total é só o labor_cost novo.
        $this->assertEqualsWithDelta(200.0, (float) $order->total, 0.001);
    }

    public function test_clear_equipments_and_items_removes_them_and_resets_the_total(): void
    {
        $orderUuid = (string) Str::uuid();
        OrderAggregate::retrieve($orderUuid)
            ->open(1341, '2026-09-08', $this->aClientId(), $this->aUserId(), false, false, false, false, false, null, null, null, null, null, null, 50.0)
            ->attachEquipment((string) Str::uuid(), null, 'Bisturi', null, null, null, null, [])
            ->addItem(2, 'Peça', 25.0)
            ->persist();

        $beforeClear = Order::with(['equipments', 'items'])->findOrFail($orderUuid);
        $this->assertSame(1, $beforeClear->equipments->count());
        $this->assertSame(1, $beforeClear->items->count());
        $this->assertEqualsWithDelta(100.0, (float) $beforeClear->total, 0.001); // 50 + 2*25

        OrderAggregate::retrieve($orderUuid)->clearEquipments()->clearItems()->persist();

        $afterClear = Order::with(['equipments', 'items'])->findOrFail($orderUuid);
        $this->assertSame(0, $afterClear->equipments->count());
        $this->assertSame(0, $afterClear->items->count());
        // Volta a ser só o labor_cost (50) — sem itens pra somar.
        $this->assertEqualsWithDelta(50.0, (float) $afterClear->total, 0.001);
    }
}
