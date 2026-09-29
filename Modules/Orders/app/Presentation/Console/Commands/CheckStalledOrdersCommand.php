<?php

namespace Modules\Orders\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Orders\Application\CheckStalledOrders;

/**
 * Agendado diário em routes/console.php (api#135).
 */
class CheckStalledOrdersCommand extends Command
{
    protected $signature = 'orders:check-stalled';

    protected $description = 'Verifica OS paradas (e aguardando aprovação), grava os marcos vencidos e aplica a baixa automática';

    public function handle(CheckStalledOrders $checkStalledOrders): int
    {
        $claimed = $checkStalledOrders();

        $this->info("{$claimed} marco(s) de OS parada registrado(s).");

        return self::SUCCESS;
    }
}
