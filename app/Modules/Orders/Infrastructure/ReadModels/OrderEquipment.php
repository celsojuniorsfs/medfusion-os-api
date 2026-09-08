<?php

namespace App\Modules\Orders\Infrastructure\ReadModels;

use App\Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'equipment_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag', 'accessories'])]
class OrderEquipment extends Model
{
    use HasFactory, HasUuids;

    // "equipment" é invariável no plural em inglês — o Eloquent adivinharia "order_equipment" em
    // vez de "order_equipments" (mesmo problema do model Equipment).
    protected $table = 'order_equipments';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Cadastro atual no catálogo do cliente — pode ser null se o equipamento foi removido do
     * catálogo depois. Os campos acima (name, brand, model...) são o retrato congelado no
     * momento da criação da OS e não dependem desta relação para exibir corretamente.
     *
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
