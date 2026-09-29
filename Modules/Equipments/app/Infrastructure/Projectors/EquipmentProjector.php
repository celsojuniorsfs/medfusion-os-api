<?php

namespace Modules\Equipments\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accessories\Domain\Events\AccessoryRemoved;
use Modules\Accessories\Domain\Events\AccessoryUpdated;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRemoved;
use Modules\EquipmentModels\Domain\Events\EquipmentModelUpdated;
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

    /**
     * Evento de OUTRO módulo (EquipmentModels), tratado aqui de propósito: Equipments pode conhecer
     * o catálogo, nunca o contrário (ver architecture.md). É projector, não reactor, porque é
     * reconstrução de projeção: um replay segue a ordem global de `stored_events.id`, então o
     * EquipmentRegistered antigo projeta o nome da época e este handler corrige em seguida.
     *
     * `order_equipments` NÃO é tocado: a OS guarda o snapshot do que foi atendido na época, e
     * corrigir o catálogo hoje não reescreve histórico (ver api-conventions.md § Snapshot do
     * equipamento na OS).
     */
    public function onEquipmentModelUpdated(EquipmentModelUpdated $event): void
    {
        $modelId = $event->aggregateRootUuid();

        Equipment::where('equipment_model_id', $modelId)->update([
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
        ]);

        // Equipamentos legados (sem vínculo gravado em evento) são re-derivados pelo trio num
        // replay, e o rename quebraria essa derivação. Por isso a busca usa o trio ANTERIOR, que o
        // evento carrega pra isso, e já grava o vínculo que faltava.
        if ($event->previousName !== null) {
            Equipment::whereNull('equipment_model_id')
                ->where('name', $event->previousName)
                ->where(fn ($query) => $event->previousBrand === null ? $query->whereNull('brand') : $query->where('brand', $event->previousBrand))
                ->where(fn ($query) => $event->previousModel === null ? $query->whereNull('model') : $query->where('model', $event->previousModel))
                ->update([
                    'equipment_model_id' => $modelId,
                    'name' => $event->name,
                    'brand' => $event->brand,
                    'model' => $event->model,
                ]);
        }

        $this->forgetCache();
    }

    /**
     * Remover entrada do catálogo só passa quando nenhum equipamento a usa (a FK é
     * restrictOnDelete), então aqui não há linha de `equipments` pra ajustar — mas a listagem
     * cacheada pode ter sido montada antes, e nada garante que ela não mencione o modelo.
     * Invalidar é barato; servir texto de um modelo que não existe mais, não.
     */
    public function onEquipmentModelRemoved(EquipmentModelRemoved $event): void
    {
        $this->forgetCache();
    }

    /**
     * Acessório renomeado/removido não muda dado nenhum em `equipments` (o nome vem pela relação,
     * não é copiado) — mas a listagem cacheada embute esse nome. Sem invalidar, ela serviria o nome
     * antigo por até uma hora.
     */
    public function onAccessoryUpdated(AccessoryUpdated $event): void
    {
        $this->forgetCache();
    }

    public function onAccessoryRemoved(AccessoryRemoved $event): void
    {
        $this->forgetCache();
    }

    public function onEquipmentPhotoAdded(EquipmentPhotoAdded $event): void
    {
        EquipmentPhoto::create([
            // id vem do evento, não gerado aqui (ver EquipmentPhotoAdded).
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
     * Eventos antigos não têm `equipmentModelId` — nesses casos procura a entrada do catálogo pelo
     * trio que o evento carrega. Busca **somente leitura**: o projector nunca cadastra modelo (criar
     * entrada aqui seria gravar evento durante um replay, não determinístico). Não achar é legítimo:
     * `null` significa "modelo desconhecido", e o trio continua intacto nas colunas próprias.
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
     * Invalida o cache incrementando um contador de versão, não `Cache::tags()->flush()` (operação
     * multi-chave, fonte conhecida de inconsistência em Redis/Valkey gerenciado com
     * réplica/cluster). Versão única pro módulo inteiro: EquipmentUpdated/EquipmentRemoved nem
     * carregam client_id no evento.
     */
    private function forgetCache(): void
    {
        Cache::increment('equipments:cache-version');
    }

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0`, pra `onEquipmentRegistered`
     * não estourar recriando linha existente. Filhos antes do pai, e FKs desligadas porque
     * `order_equipments.equipment_id` pertence a Orders (`OrderProjector::resetState()` cuida da
     * própria tabela).
     *
     * $aggregateUuid vem preenchido num replay de um agregado só (`--aggregate-uuid=X`) — sem
     * filtrar por ele aqui, zerar as tabelas inteiras apagaria todo mundo pra reconstruir um só
     * equipamento.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            EquipmentPhoto::when($aggregateUuid !== null, fn ($query) => $query->where('equipment_id', $aggregateUuid))->delete();
            EquipmentAccessory::when($aggregateUuid !== null, fn ($query) => $query->where('equipment_id', $aggregateUuid))->delete();
            Equipment::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });

        $this->forgetCache();
    }

    /**
     * Substitui as linhas do pivot por completo a cada register/update — mais simples que um diff,
     * sem ganho real de auditoria: ao contrário do PUT de Orders, aqui só a "foto" atual importa.
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
