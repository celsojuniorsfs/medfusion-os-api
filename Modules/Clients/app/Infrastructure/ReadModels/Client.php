<?php

namespace Modules\Clients\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model do módulo Clients — construído pelo ClientProjector a partir dos eventos do
 * ClientAggregate. id é o mesmo uuid do agregado.
 *
 * De propósito, sem relação `equipments()`/`orders()` aqui: Clients é lido por Equipments e
 * Orders, nunca o contrário (ver CLAUDE.md § Grafo de dependências). Este model já teve as
 * duas — removidas na auditoria de acoplamento de 13/09/2026 por não terem nenhum consumidor
 * (nenhum controller/resource/teste as chamava) e apontarem na direção errada do grafo.
 */
// "id" entra no fillable porque o ClientProjector cria a linha com o mesmo uuid do agregado
// (identidade compartilhada agregado/projeção) — não é um id "adivinhável" vindo de input HTTP.
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
