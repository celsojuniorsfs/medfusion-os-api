<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * PUT /orders/{id} substitui os equipamentos por completo (api#45) — o agregado não rastreia
 * os ids de cada OrderEquipmentAttached anexado, então "atualizar a lista" é limpar tudo e
 * anexar de novo, não um diff. Sem payload: o projector já sabe de qual OS é (aggregateRootUuid).
 */
class OrderEquipmentsCleared extends ShouldBeStored {}
