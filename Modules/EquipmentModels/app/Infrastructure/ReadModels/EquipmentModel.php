<?php

namespace Modules\EquipmentModels\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model do módulo EquipmentModels — construído pelo EquipmentModelProjector a partir dos
 * eventos do EquipmentModelAggregate. id é o mesmo uuid do agregado (mesmo padrão do resto).
 *
 * O nome "EquipmentModel" não tem relação com "Model" do Eloquent: read models deste projeto
 * moram sempre em Infrastructure\ReadModels, nunca em app/Models. Aqui "modelo" é o termo do
 * domínio mesmo (marca/modelo do aparelho, ver a tabela PT→EN em docs/api-conventions.md).
 */
// "id" no fillable pelo mesmo motivo documentado em Clients\Infrastructure\ReadModels\Client: o
// projector cria a linha com o uuid do agregado, não com um id vindo de input HTTP.
#[Fillable(['id', 'name', 'brand', 'model'])]
class EquipmentModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
