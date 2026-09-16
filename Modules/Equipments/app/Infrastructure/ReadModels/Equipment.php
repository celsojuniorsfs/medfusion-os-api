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
 * EquipmentAggregate.
 */
// "id" entra no fillable porque o EquipmentProjector cria a linha com o mesmo uuid do agregado.
// "accessories" saiu daqui (api#92) — não é mais coluna própria, virou o relacionamento
// accessories() abaixo, ligado ao pivot equipment_accessories.
// name/brand/model continuam colunas daqui mesmo com equipment_model_id apontando pro catálogo
// global (api#101): são um snapshot escrito a partir da entrada escolhida, e não redundância
// esquecida — sem eles, um event-sourcing:replay dos eventos anteriores ao api#101 (que só têm o
// trio como texto) não teria como reconstruir a linha. Ver docs/api-conventions.md.
#[Fillable(['id', 'client_id', 'equipment_model_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag'])]
class Equipment extends Model
{
    use HasFactory, HasUuids;

    // "equipment" é invariável no plural em inglês — o Eloquent adivinharia "equipment" em vez
    // de "equipments" (mesmo problema já visto na FK da migration de order_equipments).
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
