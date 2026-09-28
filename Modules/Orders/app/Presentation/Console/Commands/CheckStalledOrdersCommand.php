<?php

namespace Modules\Orders\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\CheckStalledOrders;

/**
 * Agendado diário em routes/console.php (api#135). Fino de propósito — igual aos controllers,
 * quem sabe a regra é a Action. Resolve os destinatários (administrative/general_admin) aqui, não
 * na Action: Presentation pode compor leitura entre módulos, Application não (ver
 * docs/architecture.md).
 */
class CheckStalledOrdersCommand extends Command
{
    protected $signature = 'orders:check-stalled';

    protected $description = 'Verifica OS paradas (e aguardando aprovação) e dispara os alertas/baixa automática dos marcos vencidos';

    public function handle(CheckStalledOrders $checkStalledOrders): int
    {
        $recipientEmails = User::whereIn('role', [UserRole::Administrative->value, UserRole::GeneralAdmin->value])
            ->pluck('email')
            ->all();

        $sent = $checkStalledOrders($recipientEmails);

        $this->info("{$sent} alerta(s) de OS parada disparado(s).");

        return self::SUCCESS;
    }
}
