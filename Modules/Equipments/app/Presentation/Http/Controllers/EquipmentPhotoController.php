<?php

namespace Modules\Equipments\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Equipments\Application\AddEquipmentPhoto;
use Modules\Equipments\Application\RemoveEquipmentPhoto;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;
use Modules\Equipments\Presentation\Http\Requests\EquipmentPhotoRequest;
use Modules\Equipments\Presentation\Http\Resources\EquipmentPhotoResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos do equipamento (api#102) — pedido do cliente pra registrar como o aparelho chegou e não ter
 * divergência na devolução.
 *
 * Endpoints próprios, fora da listagem de equipamentos, de propósito: a listagem é cacheada, e
 * acrescentar fotos ali significaria (a) mais objeto aninhado dentro de um payload serializado, que
 * é a classe de bug do api#99, e (b) URL assinada de validade curta congelada por uma hora no
 * cache. Aqui nada é cacheado e a URL é sempre nova.
 */
class EquipmentPhotoController
{
    public function index(string $id, string $equipmentId): JsonResponse
    {
        $this->findEquipmentOrFail($id, $equipmentId);

        $photos = EquipmentPhoto::where('equipment_id', $equipmentId)->orderBy('created_at')->get();

        return response()->json(['data' => EquipmentPhotoResource::collection($photos)]);
    }

    public function store(
        EquipmentPhotoRequest $request,
        string $id,
        string $equipmentId,
        AddEquipmentPhoto $addEquipmentPhoto,
    ): JsonResponse {
        $this->findEquipmentOrFail($id, $equipmentId);

        $file = $request->file('photo');

        // O arquivo é gravado ANTES do evento: o event store guarda a referência, nunca o binário
        // (ver EquipmentPhotoAdded). Se a transação abaixo falhar, sobra um arquivo órfão no disco
        // — preferível ao contrário (evento apontando pra arquivo que não existe, que quebraria a
        // tela toda vez que alguém abrisse o equipamento).
        $path = $file->store("equipments/{$equipmentId}", config('filesystems.default'));

        $photo = DB::transaction(fn () => $addEquipmentPhoto(
            $equipmentId,
            $path,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            $file->getSize(),
        ));

        return response()->json(['data' => new EquipmentPhotoResource($photo)], 201);
    }

    public function destroy(
        string $id,
        string $equipmentId,
        string $photoId,
        RemoveEquipmentPhoto $removeEquipmentPhoto,
    ): Response {
        $this->findEquipmentOrFail($id, $equipmentId);

        // Escopado pelo equipamento: uma foto de outro equipamento vira 404, não 403 — mesma
        // decisão já tomada no EquipmentController::update.
        $photo = EquipmentPhoto::where('equipment_id', $equipmentId)->findOrFail($photoId);

        $removeEquipmentPhoto($equipmentId, $photoId, $photo->path);

        return response()->noContent();
    }

    /**
     * Serve o arquivo. É a única rota do projeto fora do `auth:sanctum`: `<img src>` não manda
     * header Authorization, então a credencial é a assinatura da URL, gerada por
     * EquipmentPhotoResource com validade de 30 minutos (middleware `signed` na rota).
     */
    public function show(string $photoId): StreamedResponse
    {
        $photo = EquipmentPhoto::findOrFail($photoId);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, $photo->original_name, [
            'Content-Type' => $photo->mime_type,
        ]);
    }

    private function findEquipmentOrFail(string $clientId, string $equipmentId): Equipment
    {
        return Equipment::where('client_id', $clientId)->findOrFail($equipmentId);
    }
}
