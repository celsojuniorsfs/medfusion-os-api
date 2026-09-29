<?php

namespace Modules\Orders\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\Identity\Infrastructure\ReadModels\User;

/**
 * Read model do módulo Orders — construído pelo OrderProjector a partir dos eventos do
 * OrderAggregate. id é o mesmo uuid do agregado; number continua sendo o número de negócio
 * (seed 1336, editável), sem relação com a identidade do agregado.
 */
// "id" entra no fillable porque o OrderProjector cria a linha com o mesmo uuid do agregado.
#[Fillable([
    'id', 'number', 'date', 'client_id', 'user_id',
    'picked_up', 'warranty', 'technical_training', 'on_site_quote', 'rental',
    'reported_defect', 'maintenance_plan', 'notes',
    'payment_method', 'warranty_period', 'proposal_validity',
    'labor_cost', 'total', 'status', 'status_changed_at', 'certificate_number',
    'pdf_path', 'pdf_generated_at',
    // Vestigiais desde a #146 (preventiva/calibração viraram por equipamento) — não expostos
    // por OrderResource/OrderRequest, servem só de fallback em
    // OrderProjector::onOrderEquipmentAttached() pra replay de equipamentos anexados antes
    // desta mudança. Ver comentário lá.
    'preventive_maintenance', 'calibration',
])]
class Order extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'picked_up' => 'boolean',
            'warranty' => 'boolean',
            'technical_training' => 'boolean',
            'on_site_quote' => 'boolean',
            'rental' => 'boolean',
            'labor_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
            'preventive_maintenance' => 'boolean',
            'calibration' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderEquipment, $this>
     */
    public function equipments(): HasMany
    {
        // orderBy('position') — api#140: sem isso a ordem de retorno não é garantida (achado
        // como bug de teste flaky na #146), e a posição agora tem significado de negócio (letra
        // do certificado, api#61).
        return $this->hasMany(OrderEquipment::class)->orderBy('position');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
