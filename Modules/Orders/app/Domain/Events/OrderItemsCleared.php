<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Ver OrderEquipmentsCleared — mesmo raciocínio, pro lado das peças.
 */
class OrderItemsCleared extends ShouldBeStored {}
