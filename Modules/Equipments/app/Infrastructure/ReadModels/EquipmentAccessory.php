<?php

namespace Modules\Equipments\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;

/**
 * Linha do pivot equipment_accessories (equipamento × item do catálogo global de acessórios,
 * com quantidade) — construída pelo EquipmentProjector. `accessory()` compõe com o read model de
 * outro módulo (Accessories), o mesmo tipo de composição de leitura que Order::client() já faz
 * (ver docs/architecture.md § regra de fronteira — read models ficam de fora dela).
 */
#[Fillable(['id', 'equipment_id', 'accessory_id', 'quantity'])]
class EquipmentAccessory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Accessory, $this>
     */
    public function accessory(): BelongsTo
    {
        return $this->belongsTo(Accessory::class);
    }
}
