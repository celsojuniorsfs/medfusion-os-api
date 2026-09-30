<?php

namespace Modules\Alerts\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;
use Modules\Alerts\Presentation\Http\Concerns\AuthorizesAlertAccess;
use Modules\Alerts\Presentation\Http\Resources\EquipmentRevisionAlertResource;

class EquipmentRevisionAlertController
{
    use AuthorizesAlertAccess;

    /**
     * PATCH /alerts/revisions/{id}/contacted — mão única e idempotente (Q10): marcar de novo
     * devolve 200 sem mudar client_contacted_at.
     */
    public function contacted(Request $request, string $id): JsonResponse
    {
        $this->assertCanManage($request);

        $alert = EquipmentRevisionAlert::with(['equipment', 'order.client'])->findOrFail($id);

        if ($alert->client_contacted_at === null) {
            $alert->update(['client_contacted_at' => now()]);
        }

        return response()->json(['data' => new EquipmentRevisionAlertResource($alert)]);
    }
}
