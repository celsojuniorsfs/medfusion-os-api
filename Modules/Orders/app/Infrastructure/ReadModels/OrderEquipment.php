<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

#[Fillable([
    'id', 'order_id', 'equipment_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag',
    'preventive_maintenance', 'calibration',
    'situation', 'situation_changed_at', 'completed_at', 'position',
    'approval_status', 'approval_status_changed_at', 'labor_cost',
])]
class OrderEquipment extends Model
{
    use HasFactory, HasUuids;

    // "equipment" é invariável no plural em inglês — o Eloquent adivinharia "order_equipment" em
    // vez de "order_equipments" (mesmo problema do model Equipment).
    protected $table = 'order_equipments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'preventive_maintenance' => 'boolean',
            'calibration' => 'boolean',
            'situation_changed_at' => 'datetime',
            'completed_at' => 'datetime',
            'position' => 'integer',
            'approval_status_changed_at' => 'datetime',
            'labor_cost' => 'decimal:2',
        ];
    }

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

    /**
     * Lista de acessórios digitada nesta OS — snapshot livre, sem vínculo com o catálogo
     * estruturado de acessórios do equipamento (ver OrderEquipmentAccessory).
     *
     * @return HasMany<OrderEquipmentAccessory, $this>
     */
    public function accessories(): HasMany
    {
        return $this->hasMany(OrderEquipmentAccessory::class)->orderBy('position');
    }

    /**
     * Peças vinculadas a este equipamento (api#149) — itens sem vínculo (gerais) ficam só em
     * `Order::items()`.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
