<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_name', 'tax_id', 'requester', 'department', 'phone', 'address', 'city', 'postal_code'])]
class Client extends Model
{
    use HasFactory;

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
