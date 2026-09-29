<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Histórico de orçamentos gerados (api#149) — um por chamada de `POST /orders/{id}/pdf`, nunca
 * sobrescrito. `orders.pdf_path`/`pdf_generated_at` continuam existindo à parte, como ponteiro
 * pro mais recente.
 */
#[Fillable(['id', 'order_id', 'path', 'generated_at'])]
class OrderPdf extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
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
     * @return HasMany<OrderPdfEquipment, $this>
     */
    public function equipments(): HasMany
    {
        return $this->hasMany(OrderPdfEquipment::class)->orderBy('position');
    }
}
