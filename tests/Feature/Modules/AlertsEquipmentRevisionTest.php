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

class AlertsEquipmentRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function aUserId(UserRole $role = UserRole::Technician, ?string $email = null): string
    {
        $uuid = (string) Str::uuid();
        $email ??= Str::uuid().'@medfusion.example';

        UserAggregate::retrieve($uuid)
            ->register('Usuário Teste', $email, Hash::make('segredo'), $role)
            ->persist();

        return $uuid;
    }

    private function aClientId(): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                // Alguns testes deste arquivo precisam de mais de um cliente — tax_id fixo
                // estouraria a constraint única (mesmo problema documentado no CLAUDE.md pra
                // e-mail fixo de usuário de teste).
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

    /**
     * @return array{0: Order, 1: OrderEquipment}
     */
    private function anOrderWithAnEquipment(int $number, bool $preventiveMaintenance, ?string $equipmentCatalogId = null): array
    {
        $clientId = $this->aClientId();
        $order = app(OpenOrder::class)(
            $number, '2026-01-08', $clientId, $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );

        $equipmentCatalogId ??= app(RegisterEquipment::class)($clientId, 'Monitor')->id;
        app(AttachEquipmentToOrder::class)(
            $order->id, $equipmentCatalogId, 'Monitor', null, null, null, null, [],
            preventiveMaintenance: $preventiveMaintenance,
        );

        $equipment = OrderEquipment::where('order_id', $order->id)->firstOrFail();

        return [$order, $equipment];
    }

    private function resolve(Order $order, OrderEquipment $equipment, OrderEquipmentSituation $situation): OrderEquipment
    {
        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipment->id], $situation);

        return $equipment->fresh();
    }

    public function test_claims_a_month_6_milestone_even_if_order_is_partially_completed(): void
    {
        $clientId = $this->aClientId();
        $order = app(OpenOrder::class)(
            1500, '2026-01-08', $clientId, $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
        $monitor = app(RegisterEquipment::class)($clientId, 'Monitor');
        $pump = app(RegisterEquipment::class)($clientId, 'Bomba de infusão');
        app(AttachEquipmentToOrder::class)($order->id, $monitor->id, 'Monitor', null, null, null, null, [], preventiveMaintenance: true);
        app(AttachEquipmentToOrder::class)($order->id, $pump->id, 'Bomba de infusão', null, null, null, null, []);
        $equipments = OrderEquipment::where('order_id', $order->id)->orderBy('position')->get();

        OrderAggregate::retrieve($order->id)
            ->changeStatus(OrderStatus::InAnalysis)
            ->changeStatus(OrderStatus::AwaitingApproval)
            ->changeStatus(OrderStatus::Approved)
            ->persist();

        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipments[0]->id], OrderEquipmentSituation::Completed);
        $this->assertSame('partially_completed', Order::findOrFail($order->id)->status);

        $this->travel(6)->months();
        $this->travel(2)->days();

        $this->artisan('alerts:check-equipment-revisions')->assertSuccessful();

        $this->assertDatabaseHas('equipment_revision_alerts', [
            'equipment_id' => $monitor->id,
            'milestone' => 'month_6',
        ]);
    }

    public function test_returned_unrepaired_does_not_alert(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1501, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::ReturnedUnrepaired);

        $this->travel(20)->months();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseCount('equipment_revision_alerts', 0);
    }

    public function test_completed_without_preventive_maintenance_does_not_alert(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1502, preventiveMaintenance: false);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(20)->months();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseCount('equipment_revision_alerts', 0);
    }

    public function test_equipment_without_a_catalog_link_does_not_alert(): void
    {
        $clientId = $this->aClientId();
        $order = app(OpenOrder::class)(
            1503, '2026-01-08', $clientId, $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
        app(AttachEquipmentToOrder::class)($order->id, null, 'Monitor', null, null, null, null, [], preventiveMaintenance: true);
        $equipment = OrderEquipment::where('order_id', $order->id)->firstOrFail();
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(20)->months();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseCount('equipment_revision_alerts', 0);
    }

    public function test_running_twice_the_same_day_does_not_duplicate(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1504, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();

        $this->artisan('alerts:check-equipment-revisions');
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseCount('equipment_revision_alerts', 1);
    }

    public function test_month_11_is_claimed_independently_of_month_6_contact(): void
    {
        $admin = User::findOrFail($this->aUserId(UserRole::Administrative, 'administrativo@medfusion.example'));
        [$order, $equipment] = $this->anOrderWithAnEquipment(1505, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $this->assertDatabaseCount('equipment_revision_alerts', 1);

        $alert = EquipmentRevisionAlert::where('milestone', 'month_6')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$alert->id}/contacted")
            ->assertOk();

        $this->travel(5)->months();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseHas('equipment_revision_alerts', ['milestone' => 'month_11']);
    }

    public function test_billing_marks_the_alert_after_7_days_without_contact(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1506, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->travel(7)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertNotNull(EquipmentRevisionAlert::where('milestone', 'month_6')->firstOrFail()->billing_notified_at);
    }

    public function test_marking_contacted_prevents_the_billing(): void
    {
        $admin = User::findOrFail($this->aUserId(UserRole::Administrative, 'administrativo@medfusion.example'));
        [$order, $equipment] = $this->anOrderWithAnEquipment(1507, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $alert = EquipmentRevisionAlert::where('milestone', 'month_6')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$alert->id}/contacted")
            ->assertOk();

        $this->travel(7)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertNull(EquipmentRevisionAlert::findOrFail($alert->id)->billing_notified_at);
    }

    public function test_billing_is_marked_only_once(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1508, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->travel(7)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $firstBillingNotifiedAt = EquipmentRevisionAlert::where('milestone', 'month_6')->firstOrFail()->billing_notified_at;

        $this->travel(7)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertTrue($firstBillingNotifiedAt->equalTo(
            EquipmentRevisionAlert::where('milestone', 'month_6')->firstOrFail()->billing_notified_at,
        ));
    }

    /**
     * Q2/F: uma resolução mais nova do mesmo equipamento (OS diferente) supera o ciclo anterior —
     * cobrança pendente do ciclo antigo não sai mais, mesmo passando dos 7 dias.
     */
    public function test_a_new_resolution_supersedes_the_previous_cycle_and_cancels_its_pending_billing(): void
    {
        [$firstOrder, $firstEquipment] = $this->anOrderWithAnEquipment(1509, preventiveMaintenance: true);
        $this->resolve($firstOrder, $firstEquipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $this->assertDatabaseCount('equipment_revision_alerts', 1);

        $this->travel(2)->days();
        [$secondOrder, $secondEquipment] = $this->anOrderWithAnEquipment(1510, preventiveMaintenance: true, equipmentCatalogId: $firstEquipment->equipment_id);
        $this->resolve($secondOrder, $secondEquipment, OrderEquipmentSituation::Completed);
        $this->artisan('alerts:check-equipment-revisions');

        $this->travel(5)->days();
        $this->artisan('alerts:check-equipment-revisions');

        $this->assertDatabaseHas('equipment_revision_alerts', [
            'order_id' => $firstOrder->id,
            'milestone' => 'month_6',
            'billing_notified_at' => null,
        ]);
        $this->assertDatabaseMissing('equipment_revision_alerts', [
            'order_id' => $firstOrder->id,
            'milestone' => 'month_6',
            'superseded_at' => null,
        ]);
    }

    public function test_editing_the_order_does_not_reclaim_or_restart_the_cycle(): void
    {
        [$order, $equipment] = $this->anOrderWithAnEquipment(1511, preventiveMaintenance: true);
        $equipment = $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);

        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $this->assertDatabaseCount('equipment_revision_alerts', 1);

        // Mesmo efeito de um PUT /orders/{id}: limpa e reanexa o mesmo equipamento do catálogo,
        // preservando situation/situation_changed_at (OrderController::resolveEquipments).
        OrderAggregate::retrieve($order->id)->clearEquipments()->persist();
        app(AttachEquipmentToOrder::class)(
            $order->id, $equipment->equipment_id, $equipment->name, null, null, null, null, [],
            preventiveMaintenance: true,
            situation: $equipment->situation,
            situationChangedAt: $equipment->situation_changed_at->toISOString(),
        );

        $this->artisan('alerts:check-equipment-revisions');

        $this->assertSame(1, EquipmentRevisionAlert::where('equipment_id', $equipment->equipment_id)->count());
    }

    public function test_patch_contacted_rejects_technician(): void
    {
        $technician = User::findOrFail($this->aUserId(UserRole::Technician, 'tecnico@medfusion.example'));
        [$order, $equipment] = $this->anOrderWithAnEquipment(1512, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);
        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $alert = EquipmentRevisionAlert::firstOrFail();

        $this->actingAs($technician, 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$alert->id}/contacted")
            ->assertForbidden();
    }

    public function test_patch_contacted_accepts_administrative_and_is_idempotent(): void
    {
        $admin = User::findOrFail($this->aUserId(UserRole::Administrative, 'administrativo@medfusion.example'));
        [$order, $equipment] = $this->anOrderWithAnEquipment(1513, preventiveMaintenance: true);
        $this->resolve($order, $equipment, OrderEquipmentSituation::Completed);
        $this->travel(6)->months();
        $this->travel(2)->days();
        $this->artisan('alerts:check-equipment-revisions');
        $alert = EquipmentRevisionAlert::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$alert->id}/contacted")
            ->assertOk();
        $firstContactedAt = EquipmentRevisionAlert::findOrFail($alert->id)->client_contacted_at;

        $this->travel(1)->day();
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/alerts/revisions/{$alert->id}/contacted")
            ->assertOk();

        $this->assertTrue($firstContactedAt->equalTo(EquipmentRevisionAlert::findOrFail($alert->id)->client_contacted_at));
    }
}
