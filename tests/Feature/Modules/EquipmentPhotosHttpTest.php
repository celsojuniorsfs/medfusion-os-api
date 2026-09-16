<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\Projectors\EquipmentProjector;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Spatie\EventSourcing\Facades\Projectionist;
use Tests\TestCase;

class EquipmentPhotosHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Storage::fake troca o disco default por um temporário — nenhum teste escreve no disco
        // de verdade, e assertExists/assertMissing passam a funcionar.
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
            ->register(PersonType::Company, 'Hospital São Lucas', '31233218000110', null, null, null, null, null, null, null, null, null, null)
            ->persist();

        return $uuid;
    }

    private function anEquipmentId(string $clientId): string
    {
        $uuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, 'Bisturi', 'Marca X', 'Modelo X', 'SN-123', null, [])
            ->persist();

        return $uuid;
    }

    public function test_guests_cannot_access_photo_endpoints(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $this->getJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos")->assertStatus(401);
    }

    public function test_uploads_a_photo(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.original_name', 'entrada.jpg');
        $response->assertJsonStructure(['data' => ['id', 'equipment_id', 'original_name', 'mime_type', 'size', 'url']]);

        $photo = EquipmentPhoto::firstOrFail();
        $this->assertSame($equipmentId, $photo->equipment_id);
        Storage::disk(config('filesystems.default'))->assertExists($photo->path);
    }

    public function test_lists_the_photos_of_an_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        foreach (['entrada.jpg', 'saida.jpg'] as $name) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                    'photo' => UploadedFile::fake()->image($name),
                ])
                ->assertCreated();
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_rejects_a_file_that_is_not_an_image(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('photo');
    }

    public function test_rejects_a_photo_above_the_size_limit(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                // 8 MB é o limite (max:8192 em KB).
                'photo' => UploadedFile::fake()->image('gigante.jpg')->size(9000),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('photo');
    }

    public function test_removing_a_photo_deletes_the_row_and_the_file(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ])
            ->assertCreated();

        $photo = EquipmentPhoto::firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos/{$photo->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('equipment_photos', ['id' => $photo->id]);
        // Quem apagou o arquivo foi o EquipmentPhotoReactor.
        Storage::disk(config('filesystems.default'))->assertMissing($photo->path);
    }

    public function test_returns_404_when_removing_a_photo_from_another_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $otherEquipmentId = $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ])
            ->assertCreated();

        $photo = EquipmentPhoto::firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientId}/equipments/{$otherEquipmentId}/photos/{$photo->id}")
            ->assertStatus(404);
    }

    public function test_removing_the_equipment_deletes_its_photo_files(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ])
            ->assertCreated();

        $path = EquipmentPhoto::firstOrFail()->path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}")
            ->assertNoContent();

        // A linha some por cascade; o arquivo só some porque EquipmentRemoved carrega os caminhos
        // lidos antes da remoção (ver RemoveEquipment).
        $this->assertDatabaseMissing('equipment_photos', ['path' => $path]);
        Storage::disk(config('filesystems.default'))->assertMissing($path);
    }

    /**
     * Trava a propriedade que faz do `event-sourcing:replay` uma operação segura de rotina: a
     * projeção de fotos é reconstruída **sem tocar em disco nenhum**. Projector roda de novo a cada
     * replay; Reactor não — por isso apagar arquivo mora no EquipmentPhotoReactor.
     *
     * Sendo preciso sobre o risco (o comentário anterior aqui exagerava): com a deleção no
     * Projector, um replay reexecutaria as deleções já acontecidas, cujos arquivos em geral já não
     * existem — o estrago não seria "apagar tudo". Ele aparece quando o disco e o banco não estão
     * no mesmo ponto no tempo: banco restaurado de backup, arquivos íntegros, replay pra
     * reconstruir as projeções — e aí sim o replay apaga arquivos que deveriam continuar lá. Manter
     * o replay sem efeito colateral externo é o que elimina essa classe inteira de risco.
     */
    public function test_replaying_does_not_delete_photo_files(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ])
            ->assertCreated();

        $photo = EquipmentPhoto::firstOrFail();

        // Zera a projeção como um reset de verdade faria (equipment_photos some junto, por
        // cascade) e reconstrói tudo a partir do event store.
        Equipment::query()->delete();
        Projectionist::replay(collect([app(EquipmentProjector::class)]));

        // A linha voltou (projeção reconstruída) e o arquivo continua lá.
        $this->assertDatabaseHas('equipment_photos', ['id' => $photo->id, 'path' => $photo->path]);
        Storage::disk(config('filesystems.default'))->assertExists($photo->path);
    }

    public function test_serves_the_photo_through_a_signed_url(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ]);

        $url = $response->json('data.url');

        // Sem autenticação nenhuma: a assinatura da URL é a credencial (é assim que a tag <img>
        // do frontend consegue carregar a foto).
        $this->get($url)->assertOk();
    }

    public function test_rejects_an_unsigned_photo_url(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}/photos", [
                'photo' => UploadedFile::fake()->image('entrada.jpg'),
            ])
            ->assertCreated();

        $photoId = EquipmentPhoto::firstOrFail()->id;

        // Mesma rota, sem a assinatura — precisa ser recusada, senão qualquer um com o uuid veria
        // a foto pra sempre.
        $this->get("/api/v1/equipment-photos/{$photoId}")->assertStatus(403);
    }
}
