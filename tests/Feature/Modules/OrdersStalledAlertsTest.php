<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Orders\Application\CheckStalledOrders;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Tests\TestCase;

class OrdersStalledAlertsTest extends TestCase
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

    private function anOpenOrder(int $number = 1400): Order
    {
        return app(OpenOrder::class)(
            $number, '2026-09-08', $this->aClientId(), $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
    }

    public function test_claims_a_milestone_when_the_threshold_is_reached(): void
    {
        $order = $this->anOpenOrder();

        $this->travel(8)->days();

        $claimed = app(CheckStalledOrders::class)();

        $this->assertSame(1, $claimed);
        $this->assertDatabaseHas('order_stalled_alerts', [
            'order_id' => $order->id,
            'status' => 'open',
            'milestone_days' => 7,
        ]);
    }

    public function test_does_not_reclaim_the_same_milestone_on_a_second_run(): void
    {
        $this->anOpenOrder();

        $this->travel(8)->days();

        app(CheckStalledOrders::class)();
        $secondRunClaimed = app(CheckStalledOrders::class)();

        $this->assertSame(0, $secondRunClaimed);
        $this->assertDatabaseCount('order_stalled_alerts', 1);
    }

    public function test_does_not_alert_before_the_first_milestone(): void
    {
        $this->anOpenOrder();

        $this->travel(3)->days();

        $claimed = app(CheckStalledOrders::class)();

        $this->assertSame(0, $claimed);
        $this->assertDatabaseCount('order_stalled_alerts', 0);
    }

    public function test_changing_status_resets_the_stalled_count(): void
    {
        $order = $this->anOpenOrder();

        $this->travel(8)->days();

        // Muda de status pouco antes de rodar o comando — reinicia a contagem (ver
        // OrderProjector::onOrderStatusChanged), então os 8 dias acumulados em "open" não valem
        // mais pra "in_analysis".
        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::InAnalysis)->persist();

        $claimed = app(CheckStalledOrders::class)();

        $this->assertSame(0, $claimed);
    }

    public function test_awaiting_approval_at_60_days_auto_rejects_the_order(): void
    {
        $order = $this->anOpenOrder();

        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::InAnalysis)->persist();
        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::AwaitingApproval)->persist();

        $this->travel(60)->days();

        // Roda numa tacada só, 60 dias depois: pega de uma vez os 5 marcos da escada de
        // aguardando aprovação (7, 15, 30, 45, 60) — é a mesma lógica de recuperar marcos
        // perdidos se o comando ficasse um tempo sem rodar. Na operação real (cron diário), cada
        // marco dispara no seu próprio dia, um de cada vez.
        $claimed = app(CheckStalledOrders::class)();

        $this->assertSame(5, $claimed);
        $this->assertSame('not_approved', Order::findOrFail($order->id)->status);
        $this->assertDatabaseHas('order_stalled_alerts', [
            'order_id' => $order->id,
            'status' => 'awaiting_approval',
            'milestone_days' => 60,
        ]);
    }

    public function test_command_claims_milestones(): void
    {
        $order = $this->anOpenOrder();

        $this->travel(8)->days();

        $this->artisan('orders:check-stalled')->assertSuccessful();

        $this->assertDatabaseHas('order_stalled_alerts', ['order_id' => $order->id]);
    }
}
