<?php

namespace Modules\Orders\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Orders\Application\CheckEquipmentSituations;

/**
 * Agendado diário em routes/console.php (api#147). Separado de `orders:check-stalled`
 * (api#135) de propósito: escadas de marcos diferentes.
 */
class CheckEquipmentSituationsCommand extends Command
{
    protected $signature = 'orders:check-equipment-situations';

    protected $description = 'Verifica equipamentos parados numa situação não resolvida e grava os marcos vencidos';

    public function handle(CheckEquipmentSituations $checkEquipmentSituations): int
    {
        $claimed = $checkEquipmentSituations();

        $this->info("{$claimed} marco(s) de situação de equipamento registrado(s).");

        return self::SUCCESS;
    }
}
