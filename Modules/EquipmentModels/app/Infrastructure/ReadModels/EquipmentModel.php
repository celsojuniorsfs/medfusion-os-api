<?php

namespace Modules\EquipmentModels\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model construído pelo EquipmentModelProjector. "EquipmentModel" não tem relação com
 * "Model" do Eloquent — aqui "modelo" é termo de domínio (marca/modelo do aparelho).
 */
// "id" no fillable pelo mesmo motivo de Clients\Infrastructure\ReadModels\Client: o projector
// cria a linha com o uuid do agregado, não com id vindo de input HTTP.
#[Fillable(['id', 'name', 'brand', 'model'])]
class EquipmentModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
