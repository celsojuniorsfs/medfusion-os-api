<?php

namespace App\Modules\Clients\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ClientRemoved extends ShouldBeStored
{
    public function __construct() {}
}
