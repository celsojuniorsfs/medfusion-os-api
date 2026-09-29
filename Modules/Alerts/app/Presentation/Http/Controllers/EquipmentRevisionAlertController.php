<?php

namespace Modules\Alerts\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;
use Modules\Alerts\Presentation\Http\Resources\EquipmentRevisionAlertResource;
use Modules\Identity\Domain\Enums\UserRole;

/**
 * Só administrative/general_admin — mesmos destinatários dos e-mails de revisão (Q7/F8). Sem
 * middleware de papel reutilizável ainda no projeto (fica pra #137, 2º consumidor); checagem
 * inline aqui.
 */
class EquipmentRevisionAlertController
{
    /**
     * GET /alerts/revisions — disparados e ainda sem "cliente contatado". Provisório (api#136):
     * uma issue futura substitui por um GET /alerts unificado com os outros tipos de alerta.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $alerts = EquipmentRevisionAlert::query()
            ->whereNull('client_contacted_at')
            ->whereNull('superseded_at')
            ->with(['equipment', 'order.client'])
            ->orderBy('notified_at')
            ->get();

        return response()->json(['data' => EquipmentRevisionAlertResource::collection($alerts)]);
    }

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

    private function assertCanManage(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, [UserRole::Administrative, UserRole::GeneralAdmin], true),
            403,
        );
    }
}
