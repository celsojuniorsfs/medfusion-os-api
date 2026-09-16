<?php

namespace Modules\Accessories\Application;

use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;

class UpdateAccessory
{
    public function __invoke(string $id, string $name): Accessory
    {
        AccessoryAggregate::retrieve($id)->update($name)->persist();

        return Accessory::findOrFail($id);
    }
}
