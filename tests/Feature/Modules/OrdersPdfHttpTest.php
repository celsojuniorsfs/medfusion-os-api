<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Events\OrderPdfGenerated;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Tests\TestCase;

class OrdersPdfHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

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
                requester: 'Carlos',
                department: null,
                phone: '19 99712-9085',
                email: 'carlos@saolucas.example',
                address: 'Rua da Seda, 250',
                city: 'Santa Bárbara D\'Oeste',
                state: 'SP',
                postalCode: '13456789',
            )
            ->persist();

        return $uuid;
    }

    private function anOrder(string $clientId, string $userId): Order
    {
        return app(OpenOrder::class)(
            1337, '2026-09-25', $clientId, $userId,
            true, false, false, false, false, 'Sem corte', 'Troca de mosfet', 'Obs', null, null, null, 150.0,
        );
    }

    /**
     * @return list<string> order_equipment_ids, na ordem anexada
     */
    private function attachEquipments(string $orderId, int $count): array
    {
        $ids = [];

        foreach (range(1, $count) as $n) {
            app(AttachEquipmentToOrder::class)($orderId, null, "Equipamento {$n}", null, null, null, null, []);
        }

        foreach (OrderEquipment::where('order_id', $orderId)->orderBy('position')->pluck('id') as $id) {
            $ids[] = $id;
        }

        return $ids;
    }

    public function test_guests_cannot_generate_or_read_the_pdf(): void
    {
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $this->authenticatedUser()->id);

        $this->postJson("/api/v1/orders/{$order->id}/pdf")->assertStatus(401);
        $this->getJson("/api/v1/orders/{$order->id}/pdf")->assertStatus(401);
        $this->getJson("/api/v1/orders/{$order->id}/pdf/history")->assertStatus(401);
    }

    public function test_returns_404_when_reading_the_pdf_of_an_order_that_never_generated_one(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/orders/{$order->id}/pdf")
            ->assertStatus(404);
    }

    public function test_returns_404_for_an_unknown_order_on_all_three_routes(): void
    {
        $user = $this->authenticatedUser();
        $unknownId = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$unknownId}/pdf")->assertStatus(404);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$unknownId}/pdf")->assertStatus(404);

        // A rota de download exige assinatura válida antes de chegar no controller — sem uma URL
        // assinada de verdade, o middleware `signed` rejeitaria com 403 antes do 404 do
        // findOrFail entrar em jogo.
        $signedUrl = URL::temporarySignedRoute('orders.pdf.download', now()->addMinutes(30), ['id' => $unknownId]);
        $this->get($signedUrl)->assertStatus(404);
    }

    public function test_generates_the_pdf_and_records_the_event(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf");

        $response->assertOk();
        $response->assertJsonStructure(['url', 'generated_at', 'expires_at']);

        $this->assertDatabaseHas('stored_events', ['event_class' => OrderPdfGenerated::class]);

        $path = Order::findOrFail($order->id)->pdf_path;
        $this->assertNotNull($path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
    }

    public function test_shows_the_most_recent_pdf_after_generating(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->assertOk();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$order->id}/pdf");

        $response->assertOk();
        $response->assertJsonStructure(['url', 'generated_at', 'expires_at']);
    }

    public function test_generating_again_keeps_the_previous_file_and_get_points_to_the_newest(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->assertOk();
        $firstPath = Order::findOrFail($order->id)->pdf_path;

        // Nome do arquivo tem granularidade de segundo (ver OrderPdfController::store) — sem
        // avançar o relógio, os dois nomes colidiriam.
        $this->travel(1)->second();

        $second = $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->assertOk()->json();
        $secondPath = Order::findOrFail($order->id)->pdf_path;

        $this->assertNotSame($firstPath, $secondPath);
        // api#149 — histórico: o arquivo anterior não é mais apagado.
        $disk = Storage::disk(config('filesystems.default'));
        $disk->assertExists($firstPath);
        $disk->assertExists($secondPath);

        $get = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$order->id}/pdf")->assertOk()->json();
        $this->assertSame($second['generated_at'], $get['generated_at']);
    }

    public function test_downloads_a_real_pdf_through_the_signed_url(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $generate = $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->json();

        $response = $this->get($generate['url']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_downloading_without_a_valid_signature_is_forbidden(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->assertOk();

        $this->get("/api/v1/orders/{$order->id}/pdf/download")->assertForbidden();
    }

    public function test_generating_a_partial_pdf_records_only_the_included_equipments_and_activates_their_approval(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);
        [$first, $second] = $this->attachEquipments($order->id, 2);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf", [
            'order_equipment_ids' => [$first],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('order_pdf_equipments', ['name' => 'Equipamento 1']);
        $this->assertDatabaseMissing('order_pdf_equipments', ['name' => 'Equipamento 2']);

        $equipments = OrderEquipment::where('order_id', $order->id)->get()->keyBy('id');
        $this->assertSame('awaiting_approval', $equipments[$first]->approval_status);
        $this->assertNull($equipments[$second]->approval_status);
    }

    public function test_rejects_an_order_equipment_id_from_another_order_when_generating_a_pdf(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $orderA = $this->anOrder($clientId, $user->id);
        $orderB = app(OpenOrder::class)(1401, '2026-09-25', $clientId, $user->id, false, false, false, false, false, null, null, null, null, null, null, 100.0);
        [$otherOrderEquipmentId] = $this->attachEquipments($orderB->id, 1);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$orderA->id}/pdf", [
            'order_equipment_ids' => [$otherOrderEquipmentId],
        ]);

        $response->assertStatus(422);
    }

    public function test_history_lists_every_pdf_generated_with_a_download_link(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $user->id);
        [$first] = $this->attachEquipments($order->id, 2);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf", ['order_equipment_ids' => [$first]])->assertOk();
        $this->travel(1)->second();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pdf")->assertOk();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$order->id}/pdf/history");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data' => [['id', 'generated_at', 'equipments', 'url', 'expires_at']]]);
        // Mais recente primeiro (o segundo, sem filtro, incluiu os 2 equipamentos).
        $response->assertJsonCount(2, 'data.0.equipments');
        $response->assertJsonCount(1, 'data.1.equipments');
    }

    /**
     * api#149 — a garantia central do histórico: order_equipments.id troca a cada edição da OS
     * (UpdateOrder limpa e reanexa), mas o snapshot em order_pdf_equipments é independente disso.
     */
    public function test_history_entry_survives_editing_the_order(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'number' => 1402,
            'date' => '2026-09-25',
            'client_id' => $clientId,
            'labor_cost' => 150.0,
            'equipments' => [['name' => 'Monitor']],
            'items' => [],
        ])->json('data');

        $equipmentId = $created['equipments'][0]['equipment_id'];
        $orderEquipmentId = $created['equipments'][0]['id'];

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$created['id']}/pdf", [
            'order_equipment_ids' => [$orderEquipmentId],
        ])->assertOk();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            'number' => 1402,
            'date' => '2026-09-25',
            'client_id' => $clientId,
            'labor_cost' => 200.0,
            'equipments' => [['equipment_id' => $equipmentId]],
            'items' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('order_equipments', ['id' => $orderEquipmentId]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$created['id']}/pdf/history");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.0.equipments');
        $response->assertJsonPath('data.0.equipments.0.name', 'Monitor');
    }
}
