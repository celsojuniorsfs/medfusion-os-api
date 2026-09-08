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
    'labor_cost', 'total', 'status', 'certificate_number',
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
            'pdf_generated_at' => 'datetime',
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
        return $this->hasMany(OrderEquipment::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
