<?php

namespace App\Modules\Orders\Domain\Exceptions;

use App\Modules\Orders\Domain\Enums\OrderStatus;
use DomainException;

class InvalidOrderStatusTransition extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct("Não é possível mudar a OS de \"{$from->value}\" para \"{$to->value}\".");
    }
}
