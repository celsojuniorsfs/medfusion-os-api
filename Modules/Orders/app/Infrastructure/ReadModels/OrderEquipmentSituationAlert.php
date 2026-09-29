<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastro de idempotência do comando `orders:check-equipment-situations` (api#147) — não é
 * projeção de evento, só "esse marco já foi avisado". Chaveado por order_id + equipment_id
 * (catálogo), não order_equipment_id — esse é efêmero, troca a cada edição da OS.
 */
#[Fillable(['id', 'order_id', 'equipment_id', 'situation', 'situation_changed_at', 'milestone_days', 'notified_at'])]
class OrderEquipmentSituationAlert extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'situation_changed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
