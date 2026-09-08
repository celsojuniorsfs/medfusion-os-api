<?php

use App\Modules\Clients\ClientsServiceProvider;
use App\Modules\Equipments\EquipmentsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    ClientsServiceProvider::class,
    EquipmentsServiceProvider::class,
    OrdersServiceProvider::class,
];
