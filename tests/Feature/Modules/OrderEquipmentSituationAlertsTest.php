<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\ChangeOrderEquipmentSituation;
use Modules\Orders\Application\CheckEquipmentSituations;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\Mail\OrderEquipmentSituationMail;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Tests\TestCase;

class OrderEquipmentSituationAlertsTest extends TestCase
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

    /**
     * @return array{0: Order, 1: OrderEquipment}
     */
    private function anOrderWithOneEquipment(int $number = 1400): array
    {
        $clientId = $this->aClientId();
        $order = app(OpenOrder::class)(
            $number, '2026-09-08', $clientId, $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );

        // Com equipment_id do catálogo (não null) — igual ao que OrderController sempre grava na
        // prática (cadastra implicitamente se a OS não informar um existente), diferente do
        // equipamento "órfão" de catálogo que o próprio alerta agora ignora (ver Q12/api#136).
        $catalogEquipment = app(RegisterEquipment::class)($clientId, 'Monitor');
        app(AttachEquipmentToOrder::class)($order->id, $catalogEquipment->id, 'Monitor', null, null, null, null, []);

        $equipment = OrderEquipment::where('order_id', $order->id)->firstOrFail();

        return [$order, $equipment];
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

        [, $equipment] = $this->anOrderWithOneEquipment();

        $this->travel(8)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(1, $sent);
        Mail::assertSent(OrderEquipmentSituationMail::class, $administrative->email);
        Mail::assertSent(OrderEquipmentSituationMail::class, $generalAdmin->email);
        Mail::assertNotSent(OrderEquipmentSituationMail::class, $technician->email);

        $this->assertDatabaseHas('order_equipment_situation_alerts', [
            'order_id' => $equipment->order_id,
            'equipment_id' => $equipment->equipment_id,
            'situation' => 'in_analysis',
            'milestone_days' => 7,
        ]);
    }

    public function test_does_not_resend_the_same_milestone_on_a_second_run(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOrderWithOneEquipment();

        $this->travel(8)->days();

        $emails = $this->recipientEmails();
        app(CheckEquipmentSituations::class)($emails);
        $secondRunSent = app(CheckEquipmentSituations::class)($emails);

        $this->assertSame(0, $secondRunSent);
        Mail::assertSentTimes(OrderEquipmentSituationMail::class, 1);
    }

    public function test_does_not_alert_before_the_first_milestone(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOrderWithOneEquipment();

        $this->travel(3)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    public function test_changing_situation_resets_the_stalled_count(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        [, $equipment] = $this->anOrderWithOneEquipment();

        $this->travel(8)->days();

        // Muda de situação pouco antes de rodar o comando — reinicia a contagem (S5, ver
        // OrderProjector::onOrderEquipmentSituationChanged), então os 8 dias acumulados em
        // in_analysis não valem mais pra awaiting_part.
        app(ChangeOrderEquipmentSituation::class)($equipment->order_id, [$equipment->id], OrderEquipmentSituation::AwaitingPart);

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    public function test_a_resolved_equipment_does_not_alert(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        [, $equipment] = $this->anOrderWithOneEquipment();

        app(ChangeOrderEquipmentSituation::class)($equipment->order_id, [$equipment->id], OrderEquipmentSituation::Completed);

        $this->travel(20)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    /**
     * S5: manutenção externa também alerta ("pra lembrar de cobrar o fornecedor") — não é só
     * in_analysis/awaiting_part.
     */
    public function test_external_repair_also_alerts(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        [, $equipment] = $this->anOrderWithOneEquipment();

        app(ChangeOrderEquipmentSituation::class)($equipment->order_id, [$equipment->id], OrderEquipmentSituation::ExternalRepair);

        $this->travel(15)->days();

        // Numa tacada só, 15 dias depois, pega os dois marcos (7 e 15) — mesma lógica de
        // recuperar marcos perdidos do CheckStalledOrders. No cron diário real, cada marco
        // dispara no seu próprio dia.
        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(2, $sent);
    }

    /**
     * Regressão: order_equipment_id troca a cada PUT na OS (api#149) — o rastro de idempotência
     * precisa sobreviver a isso chaveando por equipment_id do catálogo, não pela linha efêmera.
     */
    public function test_editing_the_order_does_not_resend_an_already_sent_milestone(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        [$order, $equipment] = $this->anOrderWithOneEquipment();

        $this->travel(8)->days();
        app(CheckEquipmentSituations::class)($this->recipientEmails());
        Mail::assertSentTimes(OrderEquipmentSituationMail::class, 1);

        // Mesmo efeito de um PUT /orders/{id}: limpa e reanexa o mesmo equipamento do catálogo,
        // preservando situation/situation_changed_at (OrderController::resolveEquipments).
        OrderAggregate::retrieve($order->id)->clearEquipments()->persist();
        app(AttachEquipmentToOrder::class)(
            $order->id, $equipment->equipment_id, $equipment->name, $equipment->brand, $equipment->model,
            $equipment->serial_number, $equipment->asset_tag, [], false, false,
            $equipment->situation, $equipment->situation_changed_at->toISOString(),
        );

        $secondRunSent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $secondRunSent);
        Mail::assertSentTimes(OrderEquipmentSituationMail::class, 1);
    }

    /**
     * Q12 (api#136): sem equipment_id (nunca vinculado ao catálogo, ou saiu dele) não tem
     * identidade estável pra chavear o alerta.
     */
    public function test_an_equipment_without_a_catalog_link_does_not_alert(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $order = app(OpenOrder::class)(
            1402, '2026-09-08', $this->aClientId(), $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
        app(AttachEquipmentToOrder::class)($order->id, null, 'Monitor', null, null, null, null, []);

        $this->travel(20)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    /**
     * S5: só equipamento de OS que não chegou a um status final. Uma OS cancelada não alerta,
     * mesmo com o equipamento ainda tecnicamente "em análise".
     */
    public function test_an_equipment_on_a_canceled_order_does_not_alert(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        [$order] = $this->anOrderWithOneEquipment();
        OrderAggregate::retrieve($order->id)->changeStatus(OrderStatus::Canceled)->persist();

        $this->travel(20)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    /**
     * "Parcialmente concluída" continua alertando o equipamento pendente — é exatamente o
     * cenário que motivou o pedido (equipamento parado enquanto o resto da OS já andou).
     */
    public function test_an_equipment_on_a_partially_completed_order_still_alerts(): void
    {
        Mail::fake();

        User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $clientId = $this->aClientId();
        $order = app(OpenOrder::class)(
            1401, '2026-09-08', $clientId, $this->aUserId(),
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
        $monitor = app(RegisterEquipment::class)($clientId, 'Monitor');
        $pump = app(RegisterEquipment::class)($clientId, 'Bomba de infusão');
        app(AttachEquipmentToOrder::class)($order->id, $monitor->id, 'Monitor', null, null, null, null, []);
        app(AttachEquipmentToOrder::class)($order->id, $pump->id, 'Bomba de infusão', null, null, null, null, []);
        $equipments = OrderEquipment::where('order_id', $order->id)->orderBy('position')->get();

        OrderAggregate::retrieve($order->id)
            ->changeStatus(OrderStatus::InAnalysis)
            ->changeStatus(OrderStatus::AwaitingApproval)
            ->changeStatus(OrderStatus::Approved)
            ->persist();

        // Conclui o primeiro — a OS deriva pra partially_completed, o segundo continua pendente.
        app(ChangeOrderEquipmentSituation::class)($order->id, [$equipments[0]->id], OrderEquipmentSituation::Completed);
        $this->assertSame('partially_completed', Order::findOrFail($order->id)->status);

        $this->travel(8)->days();

        $sent = app(CheckEquipmentSituations::class)($this->recipientEmails());

        $this->assertSame(1, $sent);
    }

    public function test_command_resolves_recipients_and_delegates_to_the_action(): void
    {
        Mail::fake();

        $generalAdmin = User::findOrFail($this->aUserId(UserRole::GeneralAdmin, 'admingeral@medfusion.example'));
        $this->anOrderWithOneEquipment();

        $this->travel(8)->days();

        $this->artisan('orders:check-equipment-situations')->assertSuccessful();

        Mail::assertSent(OrderEquipmentSituationMail::class, $generalAdmin->email);
    }
}
