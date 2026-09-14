<?php

namespace Modules\Equipments\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Modules\Equipments\Domain\Events\EquipmentUpdated;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentAccessory;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class EquipmentProjector extends Projector
{
    public function onEquipmentRegistered(EquipmentRegistered $event): void
    {
        Equipment::create([
            'id' => $event->aggregateRootUuid(),
            'client_id' => $event->clientId,
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
        ]);

        $this->syncAccessories($event->aggregateRootUuid(), $event->accessories);
        $this->forgetCache();
    }

    public function onEquipmentUpdated(EquipmentUpdated $event): void
    {
        Equipment::whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
        ]);

        $this->syncAccessories($event->aggregateRootUuid(), $event->accessories);
        $this->forgetCache();
    }

    public function onEquipmentRemoved(EquipmentRemoved $event): void
    {
        // equipment_accessories some sozinho (cascadeOnDelete na FK, ver migration) — sem
        // valor de auditoria granular o bastante pra justificar um evento próprio de remoção,
        // ao contrário de OrderEquipmentsCleared em Orders (que audita cada troca de peça/OS).
        Equipment::whereKey($event->aggregateRootUuid())->delete();

        $this->forgetCache();
    }

    /**
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) — uma tag só pro módulo
     * inteiro, não por cliente: EquipmentUpdated/EquipmentRemoved nem carregam client_id no
     * evento, e listas por cliente são pequenas o bastante pra um cache miss a mais em clientes
     * não afetados não pesar.
     */
    private function forgetCache(): void
    {
        Cache::tags(['equipments'])->flush();
    }

    /**
     * Substitui as linhas do pivot por completo a cada register/update — mais simples que
     * calcular um diff (o que mudou, o que ficou igual) sem nenhum ganho real de auditoria: ao
     * contrário do PUT de Orders (que audita peça por peça numa OS), aqui a "foto" atual é tudo
     * que importa. Mesmo espírito do Cleared+reanexa de Orders, só que dentro do mesmo evento em
     * vez de eventos à parte.
     *
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories
     */
    private function syncAccessories(string $equipmentId, array $accessories): void
    {
        EquipmentAccessory::where('equipment_id', $equipmentId)->delete();

        foreach ($accessories as $accessory) {
            EquipmentAccessory::create([
                'id' => (string) Str::uuid(),
                'equipment_id' => $equipmentId,
                'accessory_id' => $accessory['accessory_id'],
                'quantity' => $accessory['quantity'],
            ]);
        }
    }
}
