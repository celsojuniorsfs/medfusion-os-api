<?php

namespace Modules\Identity\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Identity\Database\Factories\UserFactory;

/**
 * Read model do módulo Identity — construído pelo UserProjector a partir dos eventos do
 * UserAggregate. id é o mesmo uuid do agregado (identidade compartilhada agregado/projeção).
 */
// "id" entra no fillable porque o UserProjector cria a linha com o mesmo uuid do agregado.
#[Fillable(['id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * A convenção padrão do HasFactory não resolve o namespace de um model dentro de um módulo
     * (Modules\Identity\...) — precisa do override explícito.
     *
     * @return Factory<User>
     */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
