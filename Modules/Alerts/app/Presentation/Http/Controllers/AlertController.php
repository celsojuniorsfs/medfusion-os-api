<?php

namespace Modules\Alerts\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Alerts\Presentation\Http\AlertFeed;
use Modules\Alerts\Presentation\Http\Concerns\AuthorizesAlertAccess;

class AlertController
{
    use AuthorizesAlertAccess;

    /**
     * GET /alerts — alertas pendentes de OS parada, situação de equipamento e revisão anual.
     */
    public function index(Request $request, AlertFeed $alertFeed): JsonResponse
    {
        $this->assertCanManage($request);

        return response()->json(['data' => $alertFeed->all()]);
    }
}
