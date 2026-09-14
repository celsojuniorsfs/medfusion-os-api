<?php

namespace Modules\Accessories\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model do módulo Accessories — construído pelo AccessoryProjector a partir dos eventos do
 * AccessoryAggregate. id é o mesmo uuid do agregado (mesmo padrão de Clients/Equipments).
 */
// "id" entra no fillable pelo mesmo motivo documentado em Clients\Infrastructure\ReadModels\Client:
// o AccessoryProjector cria a linha com o uuid do agregado, não um id vindo de input HTTP.
#[Fillable(['id', 'name'])]
class Accessory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
