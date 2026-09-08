<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'name', 'brand', 'model', 'serial_number', 'asset_tag', 'accessories'])]
class Equipment extends Model
{
    use HasFactory;

    // "equipment" é invariável no plural em inglês — o Eloquent adivinharia "equipment" em vez
    // de "equipments" (mesmo problema já visto na FK da migration de order_equipments).
    protected $table = 'equipments';

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
