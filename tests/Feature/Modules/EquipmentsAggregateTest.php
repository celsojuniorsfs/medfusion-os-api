<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Equipments\Domain\EquipmentAggregate;
use Tests\TestCase;

class EquipmentsAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_an_equipment_projects_the_read_model_linked_to_the_client(): void
    {
        $clientUuid = (string) Str::uuid();
        ClientAggregate::retrieve($clientUuid)
            ->register('Hospital São Lucas', '31.233.218/0001-10', null, null, null, null, null, null)
            ->persist();

        $equipmentUuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($equipmentUuid)
            ->register($clientUuid, 'Bisturi', 'Marca X', null, 'SN-123', null, null)
            ->persist();

        $this->assertDatabaseHas('equipments', [
            'id' => $equipmentUuid,
            'client_id' => $clientUuid,
            'name' => 'Bisturi',
            'serial_number' => 'SN-123',
        ]);
    }
}
