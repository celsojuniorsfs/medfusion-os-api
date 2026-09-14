<?php

namespace Modules\Accessories\Application;

use Illuminate\Support\Str;
use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;

class RegisterAccessory
{
    public function __invoke(string $name): Accessory
    {
        $uuid = (string) Str::uuid();

        AccessoryAggregate::retrieve($uuid)
            ->register($name)
            ->persist();

        return Accessory::findOrFail($uuid);
    }
}
