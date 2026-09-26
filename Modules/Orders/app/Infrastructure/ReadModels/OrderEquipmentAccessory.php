<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Um acessório digitado pra um equipamento desta OS — snapshot livre, sem FK pro catálogo global
 * de Accessories (ao contrário de Modules\Equipments\...\EquipmentAccessory, que tem
 * accessory_id). Ver CLAUDE.md § Grafo de dependências: Orders não deve ganhar uma leitura nova do
 * módulo Accessories só por causa disso.
 */
#[Fillable(['id', 'order_equipment_id', 'name', 'quantity', 'position'])]
class OrderEquipmentAccessory extends Model
{
    use HasUuids;

    protected $table = 'order_equipment_accessories';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'position' => 'integer',
        ];
    }
}
