<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Infrastructure\Projectors\EquipmentProjector;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Spatie\EventSourcing\Facades\Projectionist;
use Tests\TestCase;

class EquipmentsHttpTest extends TestCase
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

    private function aClientId(string $name = 'Hospital São Lucas', string $taxId = '31233218000110'): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: $name,
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

    private function anEquipmentId(string $clientId, string $name = 'Bisturi', ?string $serialNumber = 'SN-123'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, $name, 'Marca X', 'Modelo X', $serialNumber, null, [])
            ->persist();

        return $uuid;
    }

    private function anAccessoryId(string $name = 'Cabo de força'): string
    {
        $uuid = (string) Str::uuid();
        AccessoryAggregate::retrieve($uuid)->register($name)->persist();

        return $uuid;
    }

    private function anEquipmentModelId(string $name = 'Ultrassom', ?string $brand = 'Sonopus', ?string $model = 'XYZ-100'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentModelAggregate::retrieve($uuid)->register($name, $brand, $model)->persist();

        return $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalEquipmentPayload(): array
    {
        return [
            'name' => 'Bisturi',
            'brand' => 'Marca X',
            'model' => 'Modelo X',
            'no_accessories' => true,
        ];
    }

    public function test_guests_cannot_access_equipment_endpoints(): void
    {
        $clientId = $this->aClientId();

        $this->getJson("/api/v1/clients/{$clientId}/equipments")->assertStatus(401);
    }

    public function test_lists_only_the_equipments_of_the_given_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $this->anEquipmentId($clientA, 'Bisturi', 'SN-A1');
        $this->anEquipmentId($clientB, 'Monitor', 'SN-B1');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson("/api/v1/clients/{$clientA}/equipments");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Bisturi');
    }

    public function test_returns_404_when_listing_equipments_of_an_unknown_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients/'.Str::uuid().'/equipments');

        $response->assertStatus(404);
    }

    public function test_creates_an_equipment_with_all_fields(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'name' => 'Bisturi Elétrico',
                'brand' => 'Marca X',
                'model' => 'BX-2000',
                'serial_number' => 'SN-123',
                'asset_tag' => 'PAT-456',
                'no_accessories' => false,
                'accessories' => [['name' => 'Cabo de força', 'quantity' => 2]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Bisturi Elétrico');
        $response->assertJsonPath('data.client_id', $clientId);
        $response->assertJsonCount(1, 'data.accessories');
        $response->assertJsonPath('data.accessories.0.name', 'Cabo de força');
        $response->assertJsonPath('data.accessories.0.quantity', 2);
        $this->assertDatabaseHas('equipments', [
            'client_id' => $clientId,
            'name' => 'Bisturi Elétrico',
            'serial_number' => 'SN-123',
        ]);
        // O acessório digitado na hora entra pro catálogo global (ver api-conventions.md §
        // Equipamentos) — próximo equipamento já pode reaproveitá-lo por accessory_id.
        $this->assertDatabaseHas('accessories', ['name' => 'Cabo de força']);
    }

    public function test_creates_an_equipment_referencing_an_existing_accessory(): void
    {
        $clientId = $this->aClientId();
        $accessoryId = $this->anAccessoryId('Pedal');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                ...$this->minimalEquipmentPayload(),
                'no_accessories' => false,
                'accessories' => [['accessory_id' => $accessoryId, 'quantity' => 1]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.accessories.0.accessory_id', $accessoryId);
        $response->assertJsonPath('data.accessories.0.name', 'Pedal');
        // Reaproveitou o existente — não duplicou no catálogo.
        $this->assertEquals(1, Accessory::where('name', 'Pedal')->count());
    }

    public function test_creates_an_equipment_with_no_accessories(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", $this->minimalEquipmentPayload());

        $response->assertCreated();
        $response->assertJsonCount(0, 'data.accessories');
    }

    public function test_returns_404_when_creating_an_equipment_for_an_unknown_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients/'.Str::uuid().'/equipments', $this->minimalEquipmentPayload());

        $response->assertStatus(404);
    }

    public function test_rejects_creation_with_missing_required_field(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", ['brand' => 'Marca X']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'model', 'no_accessories']);
    }

    public function test_rejects_creation_without_marking_no_accessories_and_without_any_accessory(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'name' => 'Bisturi',
                'brand' => 'Marca X',
                'model' => 'Modelo X',
                'no_accessories' => false,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('accessories');
    }

    public function test_accepts_a_duplicate_serial_number_in_the_same_client(): void
    {
        // Decisão documentada no openapi.yaml: serial_number repetido no mesmo cliente não é
        // bloqueado pela API — o aviso ao técnico é responsabilidade do frontend. Trava esse
        // comportamento pra não virar regressão sem querer.
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId, 'Bisturi', 'SN-123');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                ...$this->minimalEquipmentPayload(),
                'name' => 'Bisturi (unidade 2)',
                'serial_number' => 'SN-123',
            ]);

        $response->assertCreated();
    }

    public function test_updates_an_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}", [
                ...$this->minimalEquipmentPayload(),
                'name' => 'Bisturi Elétrico',
                'brand' => 'Marca Y',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Bisturi Elétrico');
        $response->assertJsonPath('data.brand', 'Marca Y');
    }

    public function test_updating_an_equipment_replaces_its_accessories(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $cabo = $this->anAccessoryId('Cabo de força');
        EquipmentAggregate::retrieve($equipmentId)
            ->update('Bisturi', 'Marca X', 'Modelo X', 'SN-123', null, [['accessory_id' => $cabo, 'quantity' => 1]])
            ->persist();

        $pedal = $this->anAccessoryId('Pedal');
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}", [
                ...$this->minimalEquipmentPayload(),
                'no_accessories' => false,
                'accessories' => [['accessory_id' => $pedal, 'quantity' => 3]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.accessories');
        $response->assertJsonPath('data.accessories.0.accessory_id', $pedal);
        $response->assertJsonPath('data.accessories.0.quantity', 3);
        $this->assertDatabaseMissing('equipment_accessories', ['equipment_id' => $equipmentId, 'accessory_id' => $cabo]);
    }

    public function test_returns_404_when_updating_an_equipment_from_another_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $equipmentId = $this->anEquipmentId($clientA);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$clientB}/equipments/{$equipmentId}", $this->minimalEquipmentPayload());

        $response->assertStatus(404);
    }

    public function test_removes_an_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('equipments', ['id' => $equipmentId]);
    }

    public function test_returns_404_when_removing_an_equipment_from_another_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $equipmentId = $this->anEquipmentId($clientA);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientB}/equipments/{$equipmentId}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('equipments', ['id' => $equipmentId]);
    }

    public function test_second_identical_listing_is_served_from_cache_without_hitting_the_database(): void
    {
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/clients/{$clientId}/equipments")->assertOk();

        DB::enableQueryLog();
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/clients/{$clientId}/equipments");
        $response->assertOk();

        // Client::findOrFail($id) roda em toda chamada (checa se o cliente ainda existe) — só a
        // consulta na tabela equipments precisa vir do cache.
        $queriedEquipments = collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'equipments'));
        $this->assertFalse($queriedEquipments, 'A segunda chamada idêntica não deveria consultar "equipments" — deveria vir do cache.');
    }

    public function test_registering_a_new_equipment_invalidates_the_listing_cache(): void
    {
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId, 'Bisturi', 'SN-1');
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(1, 'data');

        $this->anEquipmentId($clientId, 'Monitor', 'SN-2');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(2, 'data');
    }

    /**
     * Achado ao reproduzir um bug relatado em ambiente local (15/09/2026): quando a PRIMEIRA
     * leitura do módulo (nunca cadastrou nada ainda) acontece ANTES do primeiro cadastro, ela
     * cacheia uma lista vazia sob a "versão" default do contador — se esse default coincidisse
     * com o valor que o primeiro `Cache::increment()` de verdade produz (Redis trata `INCRBY`
     * numa chave inexistente como se partisse de 0, ou seja, o primeiro incremento já é 1), o
     * cadastro seguinte "sumia" da listagem: a leitura pós-cadastro caía na MESMA chave
     * versionada que já tinha a lista vazia. Diferente de
     * test_registering_a_new_equipment_invalidates_the_listing_cache (que cadastra ANTES da
     * primeira leitura) — aqui a ordem é invertida de propósito.
     */
    public function test_registering_the_first_equipment_appears_even_when_the_list_was_read_empty_before(): void
    {
        $clientId = $this->aClientId();
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(0, 'data');

        $this->anEquipmentId($clientId, 'Bisturi', 'SN-1');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(1, 'data');
    }

    public function test_creating_an_equipment_registers_its_model_in_the_global_catalog(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'name' => 'Ultrassom',
                'brand' => 'Sonopus',
                'model' => 'XYZ-100',
                'no_accessories' => true,
            ]);

        $response->assertCreated();

        $modelId = EquipmentModel::where('name', 'Ultrassom')->value('id');
        $this->assertNotNull($modelId);
        $response->assertJsonPath('data.equipment_model_id', $modelId);
    }

    public function test_creating_two_equipments_with_the_same_triple_reuses_one_catalog_entry(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $user = $this->authenticatedUser();

        // Mesmo modelo, clientes diferentes — é exatamente o ganho que o cliente pediu: o catálogo
        // é global, então o segundo cadastro não cria entrada nova.
        foreach ([$clientA, $clientB] as $clientId) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/clients/{$clientId}/equipments", [
                    'name' => 'Ultrassom',
                    'brand' => 'Sonopus',
                    'model' => 'XYZ-100',
                    'no_accessories' => true,
                ])
                ->assertCreated();
        }

        $this->assertEquals(1, EquipmentModel::where('name', 'Ultrassom')->count());
    }

    public function test_creating_an_equipment_referencing_an_existing_model(): void
    {
        $clientId = $this->aClientId();
        $modelId = $this->anEquipmentModelId('Monitor', 'Marca X', 'M-1');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'equipment_model_id' => $modelId,
                'name' => 'Monitor',
                'brand' => 'Marca X',
                'model' => 'M-1',
                'no_accessories' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.equipment_model_id', $modelId);
        $this->assertEquals(1, EquipmentModel::count());
    }

    public function test_rejects_an_unknown_equipment_model_id(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'equipment_model_id' => (string) Str::uuid(),
            ] + $this->minimalEquipmentPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('equipment_model_id');
    }

    /**
     * O teste mais importante do api#101: trava a compatibilidade dos eventos já gravados,
     * escrevendo um stored_event no formato antigo na mão e reprojetando.
     *
     * Verificado que ele pega a regressão de verdade (não só passa junto): trocando
     * `?string $equipmentModelId = null` por `string $equipmentModelId` (sem default) em
     * EquipmentRegistered, este teste falha na hora com InvalidStoredEvent. Basta um dos dois —
     * default ou nulabilidade — pro payload antigo continuar desserializando; a matriz completa
     * está em CLAUDE.md.
     *
     * Também cobre a outra metade: o EquipmentProjector resolve o modelo pelo trio quando o evento
     * não traz id, então um equipamento antigo passa a apontar pro catálogo depois de um replay,
     * sem o projector cadastrar nada (o que significaria gravar evento durante o replay).
     */
    public function test_replaying_an_event_stored_before_the_catalog_existed_still_projects(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = (string) Str::uuid();
        $modelId = $this->anEquipmentModelId('Bisturi', 'Marca X', 'Modelo X');

        DB::table('stored_events')->insert([
            'aggregate_uuid' => $equipmentId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => EquipmentRegistered::class,
            // Payload no formato anterior ao api#101: sem a chave equipmentModelId.
            'event_properties' => json_encode([
                'clientId' => $clientId,
                'name' => 'Bisturi',
                'brand' => 'Marca X',
                'model' => 'Modelo X',
                'serialNumber' => 'SN-ANTIGO',
                'assetTag' => null,
                'accessories' => [],
            ]),
            // O uuid do agregado vem daqui, não da coluna aggregate_uuid: ShouldBeStored::
            // aggregateRootUuid() lê metaData['aggregate-root-uuid'] (ver Enums\MetaData do
            // pacote). Sem isso, o projector recebe null e quebra.
            'meta_data' => json_encode(['aggregate-root-uuid' => $equipmentId, 'aggregate-root-version' => 1]),
            'created_at' => now(),
        ]);

        Projectionist::replay(collect([app(EquipmentProjector::class)]));

        $this->assertDatabaseHas('equipments', [
            'id' => $equipmentId,
            'client_id' => $clientId,
            'name' => 'Bisturi',
            'serial_number' => 'SN-ANTIGO',
            // Resolvido pelo trio, já que o evento antigo não carrega id nenhum.
            'equipment_model_id' => $modelId,
        ]);
    }

    public function test_replaying_an_old_event_without_a_matching_catalog_entry_leaves_the_model_null(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = (string) Str::uuid();

        DB::table('stored_events')->insert([
            'aggregate_uuid' => $equipmentId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => EquipmentRegistered::class,
            'event_properties' => json_encode([
                'clientId' => $clientId,
                'name' => 'Aparelho sem modelo no catálogo',
                'brand' => null,
                'model' => null,
                'serialNumber' => null,
                'assetTag' => null,
                'accessories' => [],
            ]),
            'meta_data' => json_encode(['aggregate-root-uuid' => $equipmentId, 'aggregate-root-version' => 1]),
            'created_at' => now(),
        ]);

        Projectionist::replay(collect([app(EquipmentProjector::class)]));

        // Null é o resultado certo: o projector nunca cadastra no catálogo (seria gravar evento
        // durante um replay). O nome do equipamento continua íntegro na coluna própria.
        $this->assertDatabaseHas('equipments', [
            'id' => $equipmentId,
            'name' => 'Aparelho sem modelo no catálogo',
            'equipment_model_id' => null,
        ]);
        $this->assertEquals(0, EquipmentModel::count());
    }

    public function test_backfill_seeds_the_catalog_from_existing_equipments_and_links_them(): void
    {
        $clientId = $this->aClientId();
        // anEquipmentId usa o agregado direto, sem passar pelo controller — é o equivalente mais
        // próximo de um equipamento cadastrado antes do api#101 (sem modelo nenhum).
        $first = $this->anEquipmentId($clientId, 'Bisturi', 'SN-1');
        $second = $this->anEquipmentId($clientId, 'Bisturi', 'SN-2');

        DB::table('equipments')->update(['equipment_model_id' => null]);

        $this->artisan('equipment-models:backfill')->assertSuccessful();

        // Os dois têm o mesmo trio (anEquipmentId usa Marca X / Modelo X) — uma entrada só.
        $this->assertEquals(1, EquipmentModel::count());
        $modelId = EquipmentModel::value('id');
        $this->assertDatabaseHas('equipments', ['id' => $first, 'equipment_model_id' => $modelId]);
        $this->assertDatabaseHas('equipments', ['id' => $second, 'equipment_model_id' => $modelId]);

        // Idempotente: rodar de novo não duplica nem re-liga nada.
        $this->artisan('equipment-models:backfill')->assertSuccessful();
        $this->assertEquals(1, EquipmentModel::count());
    }
}
