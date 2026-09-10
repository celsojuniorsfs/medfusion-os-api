<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class ClientsAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_client_persists_the_event_and_projects_the_read_model(): void
    {
        $uuid = (string) Str::uuid();

        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: '31233218000110',
                tradeName: null,
                stateRegistration: null,
                requester: 'Marcos',
                department: 'Manutenção',
                phone: null,
                email: null,
                address: null,
                city: null,
                state: null,
                postalCode: null,
            )
            ->persist();

        $this->assertDatabaseHas('clients', [
            'id' => $uuid,
            'person_type' => 'company',
            'name' => 'Hospital São Lucas',
            'tax_id' => '31233218000110',
        ]);
        $this->assertSame(1, EloquentStoredEvent::query()->where('aggregate_uuid', $uuid)->count());
    }

    public function test_removing_a_client_deletes_the_read_model_row(): void
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

        ClientAggregate::retrieve($uuid)->remove()->persist();

        $this->assertDatabaseMissing('clients', ['id' => $uuid]);
    }
}
