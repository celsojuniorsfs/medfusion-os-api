<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

/**
 * Snapshot de UM equipamento incluído num orçamento (api#149) — name/position congelados no
 * momento da geração, não uma FK viva pra `order_equipments` (ver comentário na migration).
 */
#[Fillable(['id', 'order_pdf_id', 'equipment_id', 'name', 'position'])]
class OrderPdfEquipment extends Model
{
    use HasFactory, HasUuids;

    // "equipment" é invariável no plural em inglês (mesmo caso de OrderEquipment/Equipment).
    protected $table = 'order_pdf_equipments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderPdf, $this>
     */
    public function orderPdf(): BelongsTo
    {
        return $this->belongsTo(OrderPdf::class);
    }

    /**
     * Cadastro atual no catálogo — pode ser null se removido depois (nullOnDelete) ou se o
     * equipamento nem estava cadastrado (implícito). O snapshot (name/position) não depende disto.
     *
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
