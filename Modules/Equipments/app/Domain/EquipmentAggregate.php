<?php

namespace Modules\Equipments\Domain;

use Modules\Equipments\Domain\Events\EquipmentPhotoAdded;
use Modules\Equipments\Domain\Events\EquipmentPhotoRemoved;
use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Modules\Equipments\Domain\Events\EquipmentUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * clientId e equipmentModelId referenciam agregados de outros módulos só pelo uuid — Equipments
 * nunca importa nada de Modules\Clients\Domain nem de Modules\EquipmentModels\Domain (regra de
 * fronteira entre módulos, ver architecture.md).
 */
class EquipmentAggregate extends AggregateRoot
{
    private bool $removed = false;

    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories
     */
    public function register(
        string $clientId,
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        array $accessories,
        ?string $equipmentModelId = null,
    ): self {
        $this->recordThat(new EquipmentRegistered(
            $clientId, $name, $brand, $model, $serialNumber, $assetTag, $accessories, $equipmentModelId,
        ));

        return $this;
    }

    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories
     */
    public function update(
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        array $accessories,
        ?string $equipmentModelId = null,
    ): self {
        $this->recordThat(new EquipmentUpdated(
            $name, $brand, $model, $serialNumber, $assetTag, $accessories, $equipmentModelId,
        ));

        return $this;
    }

    /**
     * O binário da foto não passa por aqui: o arquivo já foi gravado no disco pela Presentation e
     * o evento carrega só o caminho e os metadados (ver EquipmentPhotoAdded).
     */
    public function addPhoto(string $photoId, string $path, string $originalName, string $mimeType, int $size): self
    {
        $this->recordThat(new EquipmentPhotoAdded($photoId, $path, $originalName, $mimeType, $size));

        return $this;
    }

    public function removePhoto(string $photoId, string $path): self
    {
        $this->recordThat(new EquipmentPhotoRemoved($photoId, $path));

        return $this;
    }

    /**
     * @param  array<int, string>  $photoPaths  ver EquipmentRemoved — quem apaga os arquivos é o
     *                                          Reactor, e a essa altura as linhas já sumiram por
     *                                          cascade, então os caminhos precisam viajar no evento
     */
    public function remove(array $photoPaths = []): self
    {
        if (! $this->removed) {
            $this->recordThat(new EquipmentRemoved($photoPaths));
        }

        return $this;
    }

    protected function applyEquipmentRegistered(EquipmentRegistered $event): void {}

    protected function applyEquipmentUpdated(EquipmentUpdated $event): void {}

    protected function applyEquipmentPhotoAdded(EquipmentPhotoAdded $event): void {}

    protected function applyEquipmentPhotoRemoved(EquipmentPhotoRemoved $event): void {}

    protected function applyEquipmentRemoved(EquipmentRemoved $event): void
    {
        $this->removed = true;
    }
}
