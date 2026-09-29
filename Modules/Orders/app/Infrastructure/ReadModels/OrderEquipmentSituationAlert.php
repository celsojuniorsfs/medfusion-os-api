<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastro de idempotência do comando agendado `orders:check-equipment-situations` (api#147) — não
 * é projeção de evento nenhum, é só "esse marco, pra essa passagem do equipamento por essa
 * situação, já foi avisado". Mesmo espírito de OrderStalledAlert (api#135).
 */
#[Fillable(['id', 'order_equipment_id', 'situation', 'situation_changed_at', 'milestone_days', 'notified_at'])]
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
     * @return BelongsTo<OrderEquipment, $this>
     */
    public function orderEquipment(): BelongsTo
    {
        return $this->belongsTo(OrderEquipment::class);
    }
}
