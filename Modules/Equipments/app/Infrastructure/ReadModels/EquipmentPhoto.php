<?php

namespace Modules\Equipments\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma foto do equipamento (api#102) — o arquivo em si mora no disco configurado
 * (config('filesystems.default'): Object Storage em produção, disco local em dev); aqui fica só a
 * referência e os metadados.
 *
 * Ao contrário de EquipmentAccessory, o id não é gerado pelo projector: vem dentro do evento
 * (ver EquipmentPhotoAdded), porque aparece na URL da foto.
 */
#[Fillable(['id', 'equipment_id', 'path', 'original_name', 'mime_type', 'size'])]
class EquipmentPhoto extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
