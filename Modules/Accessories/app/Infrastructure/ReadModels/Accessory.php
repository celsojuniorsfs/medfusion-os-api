<?php

namespace Modules\Accessories\Infrastructure\ReadModels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model construído pelo AccessoryProjector. "id" entra no fillable porque o projector cria
 * a linha com o uuid do agregado, não com um id vindo de input HTTP.
 */
#[Fillable(['id', 'name'])]
class Accessory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
