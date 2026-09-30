<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\ChangeOrderEquipmentSituation;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Tests\TestCase;

class AlertsFeedTest extends TestCase
{
    use RefreshDatabase;

    private function aUser(UserRole $role = UserRole::Administrative): User
    {
        $uuid = (string) Str::uuid();

        UserAggregate::retrieve($uuid)
            ->register('Usuário Teste', Str::uuid().'@medfusion.example', Hash::make('segredo'), $role)
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
                taxId: str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT),
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

    private function anOpenOrder(int $number): Order
    {
        return app(OpenOrder::class)(
            $number, '2026-01-08', $this->aClientId(), $this->aUser(UserRole::Technician)->id,
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
    }

    /**
     * @return array{0: Order, 1: OrderEquipment}
     */
    private function anOrderWithAnEquipment(int $number, bool $preventiveMaintenance = false): array
    {
        $order = $this->anOpenOrder($number);
        $catalogEquipment = app(RegisterEquipment::class)($order->client_id, 'Monitor');
        app(AttachEquipmentToOrder::class)(
            $order->id, $catalogEquipment->id, 'Monitor', null, null, null, null, [],
            preventiveMaintenance: $preventiveMaintenance,
        );

        return [$order, OrderEquipment::where('order_id', $order->id)->firstOrFail()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feed(): array
    {
        return $this->actingAs($this->aUser(), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->json('data');
    }

    public function test_lists_a_stalled_order_with_its_envelope(): void
    {
        $order = $this->anOpenOrder(1600);
        $this->travel(8)->days();
        $this->artisan('orders:check-stalled');

        $items = $this->feed();

        $this->assertCount(1, $items);
        $this->assertSame('order_stalled', $items[0]['type']);
        $this->assertSame('OS 1600 parada há mais de 7 dias', $items[0]['title']);
        $this->assertSame(7, $items[0]['milestone_days']);
        $this->assertSame($order->id, $items[0]['order']['id']);
        $this->assertSame('Hospital São Lucas', $items[0]['order']['client_name']);
        $this->assertNull($items[0]['equipment']);
    }

    public function test_a_stalled_order_shows_only_its_highest_milestone(): void
    {
        $this->anOpenOrder(1601);
        $this->travel(7)->days();
        $this->artisan('orders:check-stalled');
        $this->travel(8)->days();
        $this->artisan('orders:check-stalled');
        $this->assertDatabaseCount('order_stalled_alerts', 2);

        $items = $this->feed();

        $this->assertCount(1, $items);
        $this->assertSame(15, $items[0]['milestone_days']);
    }

    public function test_a_stalled_alert_disappears_when_the_order_leaves_that_status(): void
    {
        $order = $this->anOpenOrder(1602);
        $this->travel(8)->days();
        $this->artisan('orders:check-stalled');
        $this->assertCount(1, $this->feed());

        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::InAnalysis)->persist();

        $this->assertSame([], $this->feed());
    }

    public function test_lists_a_stalled_equipment_with_its_envelope(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1603);
        $this->travel(8)->days();
        $this->artisan('orders:check-equipment-situations');

        $items = $this->feed();

        $this->assertCount(1, $items);
        $this->assertSame('equipment_situation', $items[0]['type']);
        $this->assertSame('OS 1603: Monitor parado há mais de 7 dias', $items[0]['title']);
        $this->assertSame($equipment->equipment_id, $items[0]['equipment']['id']);
        $this->assertSame($order->id, $items[0]['order']['id']);
    }

    public function test_a_stalled_equipment_shows_only_its_highest_milestone(): void
    {
        $this->anOrderWithAnEquipment(1604);
        $this->travel(7)->days();
        $this->artisan('orders:check-equipment-situations');
        $this->travel(8)->days();
        $this->artisan('orders:check-equipment-situations');
        $this->assertDatabaseCount('order_equipment_situation_alerts', 2);

        $items = $this->feed();

        $this->assertCount(1, $items);
        $this->assertSame(15, $items[0]['milestone_days']);
    }

    public function test_a_stalled_equipment_disappears_when_its_situation_changes(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1605);
        $this->travel(8)->days();
        $this->artisan('orders:check-equipment-situations');
        $this->assertCount(1, $this->feed());

        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipment->id], OrderEquipmentSituation::AwaitingPart);

        $this->assertSame([], $this->feed());
    }

    public function test_a_stalled_equipment_on_a_canceled_order_does_not_show(): void
    {
        [$order] = $this->anOrderWithAnEquipment(1606);
        $this->travel(8)->days();
        $this->artisan('orders:check-equipment-situations');
        $this->assertCount(1, $this->feed());

        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::Canceled)->persist();

        $this->assertSame([], $this->feed());
    }

    public function test_editing_the_order_keeps_the_equipment_alert_on_the_list(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1607);
        $this->travel(8)->days();
        $this->artisan('orders:check-equipment-situations');

        // Mesmo efeito de um PUT /orders/{id}: limpa e reanexa o mesmo equipamento do catálogo,
        // preservando situation/situation_changed_at (OrderController::resolveEquipments).
        OrderAggregate::retrieve($order->id)->clearEquipments()->persist();
        app(AttachEquipmentToOrder::class)(
            $order->id, $equipment->equipment_id, $equipment->name, null, null, null, null, [],
            situation: $equipment->situation,
            situationChangedAt: $equipment->situation_changed_at->toISOString(),
        );

        $this->assertCount(1, $this->feed());
    }

    public function test_lists_a_revision_alert_until_the_client_is_contacted(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1608, preventiveMaintenance: true);
        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipment->id], OrderEquipmentSituation::Completed);
        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $items = $this->feed();

        $this->assertCount(1, $items);
        $this->assertSame('equipment_revision', $items[0]['type']);
        $this->assertSame('Acompanhamento: Monitor (OS 1608)', $items[0]['title']);
        $this->assertSame('month_6', $items[0]['milestone']);
        $this->assertNull($items[0]['milestone_days']);

        $this->actingAs($this->aUser(), 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$items[0]['id']}/contacted")
            ->assertOk();

        $this->assertSame([], $this->feed());
    }

    public function test_a_superseded_revision_alert_does_not_show(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1609, preventiveMaintenance: true);
        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipment->id], OrderEquipmentSituation::Completed);
        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        EquipmentRevisionAlert::query()->update(['superseded_at' => now()]);

        $this->assertSame([], $this->feed());
    }

    public function test_technician_is_forbidden_and_admins_are_allowed(): void
    {
        $this->actingAs($this->aUser(UserRole::Technician), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertForbidden();

        $this->actingAs($this->aUser(UserRole::Administrative), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertOk();

        $this->actingAs($this->aUser(UserRole::GeneralAdmin), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertOk();
    }
}
