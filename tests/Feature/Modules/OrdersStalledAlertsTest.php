<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\CheckStalledOrders;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\Mail\OrderStalledMail;
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

    /**
     * @return list<string>
     */
    private function recipientEmails(): array
    {
        return User::whereIn('role', [UserRole::Administrative->value, UserRole::GeneralAdmin->value])
            ->pluck('email')
            ->all();
    }

    public function test_sends_alert_at_a_milestone_to_administrative_and_general_admin_but_not_technician(): void
    {
        Mail::fake();

        $technician = User::findOrFail($this->aUserId(UserRole::Technician, 'tecnico@medfusion.example'));
        $administrative = User::findOrFail($this->aUserId(UserRole::Administrative, 'administrativo@medfusion.example'));
        $generalAdmin = User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));

        $order = $this->anOpenOrder();

        $this->travel(8)->days();

        $sent = app(CheckStalledOrders::class)($this->recipientEmails());

        $this->assertSame(1, $sent);
        Mail::assertSent(OrderStalledMail::class, $administrative->email);
        Mail::assertSent(OrderStalledMail::class, $generalAdmin->email);
        Mail::assertNotSent(OrderStalledMail::class, $technician->email);

        $this->assertDatabaseHas('order_stalled_alerts', [
            'order_id' => $order->id,
            'status' => 'open',
            'milestone_days' => 7,
        ]);
    }

    public function test_does_not_resend_the_same_milestone_on_a_second_run(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOpenOrder();

        $this->travel(8)->days();

        $emails = $this->recipientEmails();
        app(CheckStalledOrders::class)($emails);
        $secondRunSent = app(CheckStalledOrders::class)($emails);

        $this->assertSame(0, $secondRunSent);
        Mail::assertSentTimes(OrderStalledMail::class, 1);
    }

    public function test_does_not_alert_before_the_first_milestone(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOpenOrder();

        $this->travel(3)->days();

        $sent = app(CheckStalledOrders::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    public function test_changing_status_resets_the_stalled_count(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $order = $this->anOpenOrder();

        $this->travel(8)->days();

        // Muda de status pouco antes de rodar o comando — reinicia a contagem (ver
        // OrderProjector::onOrderStatusChanged), então os 8 dias acumulados em "open" não valem
        // mais pra "in_analysis".
        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::InAnalysis)->persist();

        $sent = app(CheckStalledOrders::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    public function test_awaiting_approval_at_60_days_auto_rejects_and_sends_a_distinct_mail(): void
    {
        Mail::fake();

        $generalAdmin = User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $order = $this->anOpenOrder();

        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::InAnalysis)->persist();
        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::AwaitingApproval)->persist();

        $this->travel(60)->days();

        // Roda numa tacada só, 60 dias depois: pega de uma vez os 5 marcos da escada de
        // aguardando aprovação (7, 15, 30, 45, 60) — é a mesma lógica de recuperar marcos
        // perdidos se o comando ficasse um tempo sem rodar. Na operação real (cron diário), cada
        // marco dispara no seu próprio dia, um de cada vez.
        $sent = app(CheckStalledOrders::class)($this->recipientEmails());

        $this->assertSame(5, $sent);
        $this->assertSame('not_approved', Order::findOrFail($order->id)->status);

        Mail::assertSent(
            OrderStalledMail::class,
            fn (OrderStalledMail $mail) => $mail->hasTo($generalAdmin->email)
                && $mail->autoRejected === true && $mail->milestoneDays === 60,
        );
    }

    public function test_command_resolves_recipients_and_delegates_to_the_action(): void
    {
        Mail::fake();

        $generalAdmin = User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOpenOrder();

        $this->travel(8)->days();

        $this->artisan('orders:check-stalled')->assertSuccessful();

        Mail::assertSent(OrderStalledMail::class, $generalAdmin->email);
    }
}
