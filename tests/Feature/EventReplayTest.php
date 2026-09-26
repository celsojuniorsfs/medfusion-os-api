<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Infrastructure\Projectors\ClientProjector;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\Equipments\Application\AddEquipmentPhoto;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\Projectors\EquipmentProjector;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;
use Modules\Identity\Domain\UserAggregate;
use Modules\Orders\Application\AddOrderItem;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Spatie\EventSourcing\Facades\Projectionist;
use Tests\TestCase;

/**
 * api#107: `event-sourcing:replay` só funciona se cada projector souber zerar o próprio estado
 * antes de reprojetar (`resetState()`). `RefreshDatabase` não serve aqui — ele embrulha o teste
 * numa transação, e o SQLite ignora `PRAGMA foreign_keys=0` dentro de uma transação (ver
 * CLAUDE.md), o que mascararia justamente o problema de FK entre módulos que o `resetState()`
 * precisa resolver.
 *
 * Colunas voláteis (timestamps, e o `id` de `equipment_accessories`/`order_equipment_accessories`/
 * `order_items` — gerado por `HasUuids`/`Str::uuid()` no momento da escrita, não lido do evento)
 * ficam fora da comparação: o contrato do replay é a projeção terminar com os MESMOS dados, não
 * com os mesmos ids internos de linhas que nada de fora referencia.
 *
 * `order_equipments` NÃO está nessa lista (ao contrário de antes): seu `id` agora vem de
 * `OrderEquipmentAttached::orderEquipmentId`, gerado em `AttachEquipmentToOrder` e persistido no
 * evento — determinístico entre a execução original e o replay. Mantê-lo fora da lista de
 * voláteis é o que PROVA isso: se o id voltasse a ser gerado no projector, este teste falharia
 * (a FK de order_equipment_accessories.order_equipment_id também deixaria de bater).
 */
class EventReplayTest extends TestCase
{
    use DatabaseMigrations;

    private const array VOLATILE_ID_TABLES = ['equipment_accessories', 'order_equipment_accessories', 'order_items'];

    public function test_replaying_all_projectors_reconstructs_an_identical_projection(): void
    {
        Storage::fake(config('filesystems.default'));

        [$photo, $tables] = $this->seedFullDataset();

        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => $this->snapshot($table)])->all();

        $this->artisan('event-sourcing:replay', ['--force' => true])->assertSuccessful();

        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->snapshot($table), "A tabela `{$table}` não bateu com a projeção original depois do replay.");
        }

        Storage::disk(config('filesystems.default'))->assertExists($photo->path);
    }

    public function test_replaying_only_the_equipment_projector_preserves_the_link_to_orders(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);
        $userId = $this->aUserId();
        $order = $this->anOrderWithEquipment($clientId, $userId, $equipmentId);

        Projectionist::replay(collect([app(EquipmentProjector::class)]));

        $this->assertDatabaseHas('order_equipments', [
            'order_id' => $order->id,
            'equipment_id' => $equipmentId,
        ]);
    }

    /**
     * `--aggregate-uuid=X` (replay de um agregado só) chama `resetState($aggregateUuid)` com o
     * uuid preenchido — o spatie faz isso incondicionalmente (ver Projectionist::replay), então um
     * `resetState()` que ignorasse o parâmetro e sempre apagasse a tabela inteira transformaria
     * este comando pontual num apagão de todo mundo antes de reconstruir só um cliente.
     */
    public function test_replaying_a_single_aggregate_does_not_wipe_other_aggregates(): void
    {
        $survivorId = $this->aClientId('31233218000110');
        $targetId = $this->aClientId('11222333000181');

        Projectionist::replay(collect([app(ClientProjector::class)]), 0, null, $targetId);

        $this->assertDatabaseHas('clients', ['id' => $survivorId]);
        $this->assertDatabaseHas('clients', ['id' => $targetId]);
        $this->assertSame(2, DB::table('clients')->count());
    }

    /**
     * Mesma garantia que o teste acima, só que num projector com tabelas filhas por FK
     * (equipment_photos/equipment_accessories) em vez de `whereKey` direto.
     */
    public function test_replaying_a_single_equipment_does_not_wipe_another_equipments_photos(): void
    {
        $clientId = $this->aClientId();
        $survivorId = $this->anEquipmentId($clientId);
        $targetId = $this->anEquipmentId($clientId);

        Storage::fake(config('filesystems.default'));
        $survivorPhotoPath = "equipments/{$survivorId}/foto.jpg";
        Storage::disk(config('filesystems.default'))->put($survivorPhotoPath, 'conteúdo-fake');
        app(AddEquipmentPhoto::class)($survivorId, $survivorPhotoPath, 'foto.jpg', 'image/jpeg', 13);

        Projectionist::replay(collect([app(EquipmentProjector::class)]), 0, null, $targetId);

        $this->assertDatabaseHas('equipments', ['id' => $survivorId]);
        $this->assertDatabaseHas('equipments', ['id' => $targetId]);
        $this->assertDatabaseHas('equipment_photos', ['equipment_id' => $survivorId]);
    }

    /**
     * @return array{0: EquipmentPhoto, 1: array<int, string>}
     */
    private function seedFullDataset(): array
    {
        $userId = $this->aUserId();
        $clientId = $this->aClientId();
        $accessoryId = $this->anAccessoryId();
        $modelId = $this->anEquipmentModelId();

        $equipmentId = (string) Str::uuid();
        EquipmentAggregate::retrieve($equipmentId)
            ->register($clientId, 'Bisturi', 'WEM', 'SS-501S', '03140', null, [
                ['accessory_id' => $accessoryId, 'quantity' => 1],
            ], $modelId)
            ->persist();

        $photoPath = "equipments/{$equipmentId}/foto.jpg";
        Storage::disk(config('filesystems.default'))->put($photoPath, 'conteúdo-fake');

        $photo = app(AddEquipmentPhoto::class)($equipmentId, $photoPath, 'foto.jpg', 'image/jpeg', 13);

        $order = $this->anOrderWithEquipment($clientId, $userId, $equipmentId);
        app(AddOrderItem::class)($order->id, 2, 'Mosfet alta tensão', 15.5);

        return [$photo, [
            'users', 'clients', 'accessories', 'equipment_models',
            'equipments', 'equipment_accessories', 'equipment_photos',
            'orders', 'order_equipments', 'order_equipment_accessories', 'order_items',
        ]];
    }

    private function anOrderWithEquipment(string $clientId, string $userId, string $equipmentId): Order
    {
        $order = app(OpenOrder::class)(
            random_int(1337, 999999), '2026-09-25', $clientId, $userId,
            true, false, false, false, false, 'Sem corte', 'Troca de mosfet', 'Observação', null, null, null, 150.0,
        );

        return app(AttachEquipmentToOrder::class)($order->id, $equipmentId, 'Bisturi', 'WEM', 'SS-501S', '03140', null, [
            ['name' => 'Cabo de força', 'quantity' => 2],
            ['name' => 'Pedal', 'quantity' => 1],
        ]);
    }

    private function aUserId(): string
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))->persist();

        return $uuid;
    }

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
                requester: 'Carlos',
                department: null,
                phone: '35997637815',
                email: 'carlos@saolucas.example',
                address: 'Rua da Seda, 250',
                city: 'Santa Bárbara D\'Oeste',
                state: 'SP',
                postalCode: '13456789',
            )
            ->persist();

        return $uuid;
    }

    private function anEquipmentId(string $clientId): string
    {
        $uuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($uuid)->register($clientId, 'Monitor', 'Dixtal', 'DX-2020', 'SN-88A1', null, [])->persist();

        return $uuid;
    }

    private function anAccessoryId(): string
    {
        $uuid = (string) Str::uuid();
        AccessoryAggregate::retrieve($uuid)->register('Cabo de força')->persist();

        return $uuid;
    }

    private function anEquipmentModelId(): string
    {
        $uuid = (string) Str::uuid();
        EquipmentModelAggregate::retrieve($uuid)->register('Bisturi', 'WEM', 'SS-501S')->persist();

        return $uuid;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(string $table): array
    {
        $exclude = in_array($table, self::VOLATILE_ID_TABLES, true)
            ? ['id', 'created_at', 'updated_at']
            : ['created_at', 'updated_at'];

        return DB::table($table)->get()
            ->map(function ($row) use ($exclude) {
                $row = (array) $row;
                foreach ($exclude as $column) {
                    unset($row[$column]);
                }

                return $row;
            })
            ->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))
            ->values()
            ->all();
    }
}
