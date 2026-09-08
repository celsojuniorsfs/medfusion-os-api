<?php

namespace Modules\Equipments\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clients\Infrastructure\ReadModels\Client;

/**
 * Read model do módulo Equipments — construído pelo EquipmentProjector. id é o mesmo uuid do
 * EquipmentAggregate.
 */
// "id" entra no fillable porque o EquipmentProjector cria a linha com o mesmo uuid do agregado.
#[Fillable(['id', 'client_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag', 'accessories'])]
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
}
