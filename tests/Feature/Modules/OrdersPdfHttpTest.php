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
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Events\OrderPdfGenerated;
use Modules\Orders\Infrastructure\ReadModels\Order;
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

    public function test_guests_cannot_generate_or_read_the_pdf(): void
    {
        $clientId = $this->aClientId();
        $order = $this->anOrder($clientId, $this->authenticatedUser()->id);

        $this->postJson("/api/v1/orders/{$order->id}/pdf")->assertStatus(401);
        $this->getJson("/api/v1/orders/{$order->id}/pdf")->assertStatus(401);
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

    public function test_generating_twice_keeps_both_files_and_get_points_to_the_newest(): void
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
}
