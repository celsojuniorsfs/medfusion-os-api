<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Domain\Exceptions\DuplicateOrderNumberException;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class UpdateOrder
{
    /** Ver o mesmo comentário em OpenOrder::TRANSACTION_ATTEMPTS. */
    private const int TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly OrderService $orderService) {}

    /**
     * PUT /orders/{id} — substitui os dados de cabeçalho e a lista inteira de equipamentos/itens
     * (ver OrderAggregate::clearEquipments()/clearItems()). Quem anexa os equipamentos/itens
     * novos é o controller, depois desta chamada — mesma composição com o módulo Equipments que
     * já existe no create (resolver equipment_id/cadastrar novo é responsabilidade dele, não
     * desta Action).
     *
     * @throws DuplicateOrderNumberException quando o número já está em uso por OUTRA OS —
     *                                       $ignoreOrderId evita falso-positivo quando a OS
     *                                       mantém o próprio número.
     */
    public function __invoke(
        string $orderId,
        int $number,
        string $date,
        string $clientId,
        bool $pickedUp = false,
        bool $warranty = false,
        bool $technicalTraining = false,
        bool $onSiteQuote = false,
        bool $rental = false,
        ?string $reportedDefect = null,
        ?string $maintenancePlan = null,
        ?string $notes = null,
        ?string $paymentMethod = null,
        ?string $warrantyPeriod = null,
        ?string $proposalValidity = null,
        ?float $laborCost = null,
    ): Order {
        $this->orderService->assertNumberIsAvailable($number, ignoreOrderId: $orderId);

        try {
            DB::transaction(function () use (
                $orderId, $number, $date, $clientId,
                $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                $reportedDefect, $maintenancePlan, $notes,
                $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
            ) {
                OrderAggregate::retrieve($orderId)
                    ->update(
                        $number, $date, $clientId,
                        $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                        $reportedDefect, $maintenancePlan, $notes,
                        $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
                    )
                    ->clearEquipments()
                    ->clearItems()
                    ->persist();
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateOrderNumberException;
        }

        return Order::findOrFail($orderId);
    }
}
