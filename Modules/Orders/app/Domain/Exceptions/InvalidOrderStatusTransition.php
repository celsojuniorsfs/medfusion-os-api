<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Modules\Orders\Domain\Enums\OrderStatus;

class InvalidOrderStatusTransition extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct("Não é possível mudar a OS de \"{$from->value}\" para \"{$to->value}\".");
    }
}
