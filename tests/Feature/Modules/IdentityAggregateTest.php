<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class IdentityAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_user_persists_the_event_and_projects_the_read_model(): void
    {
        $uuid = (string) Str::uuid();

        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        $this->assertDatabaseHas('users', ['id' => $uuid, 'email' => 'ana@medfusion.example']);
        $this->assertSame(1, EloquentStoredEvent::query()->where('aggregate_uuid', $uuid)->count());

        $this->assertTrue(Hash::check('segredo', User::findOrFail($uuid)->password));
    }

    public function test_changing_password_updates_the_read_model_without_a_new_user_row(): void
    {
        $uuid = (string) Str::uuid();

        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        UserAggregate::retrieve($uuid)
            ->changePassword(Hash::make('nova-senha'))
            ->persist();

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('nova-senha', User::findOrFail($uuid)->password));
        $this->assertSame(2, EloquentStoredEvent::query()->where('aggregate_uuid', $uuid)->count());
    }
}
