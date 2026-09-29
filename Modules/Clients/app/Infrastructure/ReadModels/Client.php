<?php

namespace Modules\Clients\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model do módulo Clients, construído pelo ClientProjector; id é o mesmo uuid do agregado.
 * De propósito, sem relação `equipments()`/`orders()`: Clients é lido por Equipments e Orders,
 * nunca o contrário (ver CLAUDE.md § Grafo de dependências).
 */
// "id" está no fillable porque o ClientProjector cria a linha com o uuid do agregado (identidade
// compartilhada), não porque venha de input HTTP.
#[Fillable([
    'id', 'person_type', 'name', 'trade_name', 'tax_id', 'state_registration', 'requester',
    'department', 'phone', 'email', 'address', 'city', 'state', 'postal_code',
])]
class Client extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
