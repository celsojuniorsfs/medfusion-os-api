<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastro de idempotência do comando agendado `orders:check-stalled` (api#135) — não é projeção de
 * evento nenhum, é só "esse marco, pra essa passagem da OS por esse status, já foi avisado".
 */
#[Fillable(['id', 'order_id', 'status', 'status_changed_at', 'milestone_days', 'notified_at'])]
class OrderStalledAlert extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
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
