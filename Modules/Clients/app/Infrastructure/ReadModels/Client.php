<?php

namespace Modules\Clients\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * Read model do módulo Clients — construído pelo ClientProjector a partir dos eventos do
 * ClientAggregate. id é o mesmo uuid do agregado.
 */
// "id" entra no fillable porque o ClientProjector cria a linha com o mesmo uuid do agregado
// (identidade compartilhada agregado/projeção) — não é um id "adivinhável" vindo de input HTTP.
#[Fillable([
    'id', 'person_type', 'name', 'trade_name', 'tax_id', 'state_registration', 'requester',
    'department', 'phone', 'email', 'address', 'city', 'state', 'postal_code',
])]
class Client extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
