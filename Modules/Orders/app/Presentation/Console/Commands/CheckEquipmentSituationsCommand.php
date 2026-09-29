<?php

namespace Modules\Orders\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\CheckEquipmentSituations;

/**
 * Agendado diário em routes/console.php (api#147). Separado de `orders:check-stalled`
 * (api#135) de propósito: escadas de marcos diferentes.
 */
class CheckEquipmentSituationsCommand extends Command
{
    protected $signature = 'orders:check-equipment-situations';

    protected $description = 'Verifica equipamentos parados numa situação não resolvida e dispara os alertas dos marcos vencidos';

    public function handle(CheckEquipmentSituations $checkEquipmentSituations): int
    {
        $recipientEmails = User::whereIn('role', [UserRole::Administrative->value, UserRole::GeneralAdmin->value])
            ->pluck('email')
            ->all();

        $sent = $checkEquipmentSituations($recipientEmails);

        $this->info("{$sent} alerta(s) de situação de equipamento disparado(s).");

        return self::SUCCESS;
    }
}
