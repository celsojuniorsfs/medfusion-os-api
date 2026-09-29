<?php

namespace Modules\Equipments\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Clients\Infrastructure\ReadModels\Client;

/**
 * Read model do módulo Equipments — construído pelo EquipmentProjector. id é o mesmo uuid do
 * EquipmentAggregate ("id" por isso entra no fillable).
 *
 * name/brand/model continuam colunas daqui mesmo com equipment_model_id apontando pro catálogo
 * global (api#101): são um snapshot da entrada escolhida, não redundância esquecida — sem eles um
 * replay dos eventos anteriores ao api#101 (só texto) não reconstruiria a linha. Ver
 * docs/api-conventions.md.
 */
#[Fillable(['id', 'client_id', 'equipment_model_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag'])]
class Equipment extends Model
{
    use HasFactory, HasUuids;

    // "equipment" é invariável no plural em inglês — o Eloquent adivinharia "equipment" em vez
    // de "equipments".
    protected $table = 'equipments';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<EquipmentAccessory, $this>
     */
    public function accessories(): HasMany
    {
        return $this->hasMany(EquipmentAccessory::class);
    }

    /**
     * Não carregada na listagem de equipamentos de propósito — fotos têm endpoint próprio
     * (ver EquipmentPhotoController) pra não entrarem no payload cacheado.
     *
     * @return HasMany<EquipmentPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EquipmentPhoto::class);
    }
}
