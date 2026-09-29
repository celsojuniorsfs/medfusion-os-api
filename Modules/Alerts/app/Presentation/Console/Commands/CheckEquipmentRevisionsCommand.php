<?php

namespace Modules\Alerts\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Alerts\Application\CheckEquipmentRevisions;
use Modules\Identity\Domain\Enums\UserRole;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;

/**
 * Agendado diário em routes/console.php (api#136). Monta os ciclos elegíveis lendo Orders
 * (Presentation pode compor entre módulos — Application, não, ver ModuleBoundariesTest) e
 * repassa dados puros pra Action.
 */
class CheckEquipmentRevisionsCommand extends Command
{
    protected $signature = 'alerts:check-equipment-revisions';

    protected $description = 'Verifica equipamentos com manutenção preventiva concluída há 6/11 meses e dispara os alertas de revisão';

    public function handle(CheckEquipmentRevisions $checkEquipmentRevisions): int
    {
        $recipientEmails = User::whereIn('role', [UserRole::Administrative->value, UserRole::GeneralAdmin->value])
            ->pluck('email')
            ->all();

        $billingEmails = User::where('role', UserRole::GeneralAdmin->value)->pluck('email')->all();

        $result = $checkEquipmentRevisions($this->eligibleCycles(), $recipientEmails, $billingEmails);

        $this->info("{$result['revisions_sent']} aviso(s) de revisão disparado(s), {$result['billing_sent']} cobrança(s).");

        return self::SUCCESS;
    }

    /**
     * A última resolução (completed ou returned_unrepaired) de cada equipamento, entre todas as
     * OS que ele já passou — pode ser de uma OS diferente da mais recente ativa agora (Q9). Só
     * entra no retorno quando essa última resolução é `completed` com preventiva: as demais não
     * têm ciclo — mas precisam "desaparecer" daqui pra Action saber que um ciclo antigo (se
     * houver) foi superado (ver CheckEquipmentRevisions::supersedeStaleCycles).
     *
     * O "última por equipamento" é filtrado em SQL (subquery correlacionada), não em PHP depois
     * do `get()` — um equipamento com muitas OS's ao longo dos anos não pode crescer o custo desta
     * consulta (e o eager load de order.client) com todo o histórico dele, só a linha vencedora.
     *
     * @return list<array{equipment_id: string, order_id: string, base_date: string, order_number: int, client_name: ?string}>
     */
    private function eligibleCycles(): array
    {
        $resolvedSituations = [OrderEquipmentSituation::Completed->value, OrderEquipmentSituation::ReturnedUnrepaired->value];

        return OrderEquipment::query()
            ->whereNotNull('equipment_id')
            ->whereIn('situation', $resolvedSituations)
            ->whereNotNull('situation_changed_at')
            ->whereRaw('situation_changed_at = (
                select max(oe2.situation_changed_at) from order_equipments oe2
                where oe2.equipment_id = order_equipments.equipment_id
                and oe2.situation in (?, ?)
                and oe2.situation_changed_at is not null
            )', $resolvedSituations)
            ->with('order.client')
            ->get()
            // Empate exato de situation_changed_at entre duas OS's do mesmo equipamento (raro) —
            // a subquery pode devolver mais de uma linha; só precisamos de uma.
            ->unique('equipment_id')
            ->filter(fn (OrderEquipment $equipment) => $equipment->situation === OrderEquipmentSituation::Completed->value && $equipment->preventive_maintenance)
            ->map(fn (OrderEquipment $equipment) => [
                'equipment_id' => $equipment->equipment_id,
                'order_id' => $equipment->order_id,
                'base_date' => $equipment->situation_changed_at->toISOString(),
                'order_number' => $equipment->order->number,
                'client_name' => $equipment->order->client?->name,
            ])
            ->values()
            ->all();
    }
}
