<?php

namespace Modules\Equipments\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Modules\Equipments\Domain\Events\EquipmentPhotoAdded;
use Modules\Equipments\Domain\Events\EquipmentPhotoRemoved;
use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Modules\Equipments\Domain\Events\EquipmentUpdated;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentAccessory;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class EquipmentProjector extends Projector
{
    public function onEquipmentRegistered(EquipmentRegistered $event): void
    {
        Equipment::create([
            'id' => $event->aggregateRootUuid(),
            'client_id' => $event->clientId,
            'equipment_model_id' => $this->resolveEquipmentModelId($event->equipmentModelId, $event->name, $event->brand, $event->model),
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
            'equipment_model_id' => $this->resolveEquipmentModelId($event->equipmentModelId, $event->name, $event->brand, $event->model),
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
        ]);

        $this->syncAccessories($event->aggregateRootUuid(), $event->accessories);
        $this->forgetCache();
    }

    public function onEquipmentPhotoAdded(EquipmentPhotoAdded $event): void
    {
        EquipmentPhoto::create([
            // id vem do evento, não gerado aqui (ver EquipmentPhotoAdded): ele aparece na URL da
            // foto, então precisa sobreviver a um replay.
            'id' => $event->photoId,
            'equipment_id' => $event->aggregateRootUuid(),
            'path' => $event->path,
            'original_name' => $event->originalName,
            'mime_type' => $event->mimeType,
            'size' => $event->size,
        ]);
    }

    public function onEquipmentPhotoRemoved(EquipmentPhotoRemoved $event): void
    {
        // Só a linha. O arquivo no disco é com o EquipmentPhotoReactor — apagar arquivo aqui faria
        // um event-sourcing:replay destruir todas as fotos do sistema.
        EquipmentPhoto::whereKey($event->photoId)->delete();
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
     * Eventos gravados antes do api#101 não têm `equipmentModelId` (o parâmetro tem default no
     * construtor justamente pra eles continuarem desserializando) — nesses casos procura a entrada
     * do catálogo pelo trio que o evento carrega. É uma busca **somente leitura**: o projector
     * nunca cadastra modelo. Criar entrada de catálogo aqui significaria gravar evento durante um
     * `event-sourcing:replay` — escrever no event store enquanto ele é relido, não determinístico
     * e crescendo a cada replay. Não achar é um resultado legítimo: `null` quer dizer "modelo
     * desconhecido" pra aquele equipamento antigo, e o nome/marca/modelo dele continuam intactos
     * nas colunas próprias.
     */
    private function resolveEquipmentModelId(?string $equipmentModelId, string $name, ?string $brand, ?string $model): ?string
    {
        if ($equipmentModelId !== null) {
            return $equipmentModelId;
        }

        // whereNull quando o valor é nulo: `where('brand', null)` vira `brand = NULL` em SQL, que
        // nunca é verdadeiro — equipamento antigo sem marca jamais acharia o modelo dele, mesmo
        // existindo no catálogo.
        return EquipmentModel::where('name', $name)
            ->where(fn ($query) => $brand === null ? $query->whereNull('brand') : $query->where('brand', $brand))
            ->where(fn ($query) => $model === null ? $query->whereNull('model') : $query->where('model', $model))
            ->value('id');
    }

    /**
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) incrementando um contador
     * de versão — não `Cache::tags()->flush()` (achado em produção: operação multi-chave, fonte
     * conhecida de comportamento inconsistente em Redis/Valkey gerenciado com réplica/cluster).
     * Uma versão só pro módulo inteiro, não por cliente: EquipmentUpdated/EquipmentRemoved nem
     * carregam client_id no evento, e listas por cliente são pequenas o bastante pra um cache
     * miss a mais em clientes não afetados não pesar.
     */
    private function forgetCache(): void
    {
        Cache::increment('equipments:cache-version');
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
