<?php

namespace Modules\Alerts\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Infrastructure\ReadModels\Order;

#[Fillable([
    'id', 'equipment_id', 'order_id', 'base_date', 'milestone', 'due_date',
    'notified_at', 'client_contacted_at', 'billing_notified_at', 'superseded_at',
])]
class EquipmentRevisionAlert extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'base_date' => 'datetime',
            'due_date' => 'datetime',
            'notified_at' => 'datetime',
            'client_contacted_at' => 'datetime',
            'billing_notified_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
