<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Equipments\Domain\EquipmentAggregate;
use Tests\TestCase;

class EquipmentsAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_an_equipment_projects_the_read_model_linked_to_the_client(): void
    {
        $clientUuid = (string) Str::uuid();
        ClientAggregate::retrieve($clientUuid)
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

        $equipmentUuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($equipmentUuid)
            ->register($clientUuid, 'Bisturi', 'Marca X', null, 'SN-123', null, [])
            ->persist();

        $this->assertDatabaseHas('equipments', [
            'id' => $equipmentUuid,
            'client_id' => $clientUuid,
            'name' => 'Bisturi',
            'serial_number' => 'SN-123',
        ]);
    }
}
