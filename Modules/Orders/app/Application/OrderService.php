<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Exceptions\DuplicateOrderNumberException;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * Exceção deliberada ao padrão de Actions (docs/architecture.md § Actions, sem command bus):
 * consulta pura sobre o read model, sem agregado envolvido — não faz sentido forçá-la no formato
 * retrieve→comando→persist. Nome já decidido na análise do api #23 (api-conventions.md).
 */
class OrderService
{
    /**
     * Só uma sugestão de UI — nunca reserva o número (ver api-conventions.md § Concorrência na
     * numeração da OS). Reavaliada a cada chamada; se o técnico sobrescrever para um número bem
     * maior, os números pulados ficam permanentemente livres.
     */
    public function nextNumber(): int
    {
        return (int) (Order::max('number') ?? 1336) + 1;
    }

    /**
     * Pré-check rápido: dá a mensagem certa no caso comum (sem corrida). Não é a garantia real de
     * unicidade — essa é a constraint `unique` no banco (ver OpenOrder, que também captura a
     * violação da constraint como rede de segurança para o caso raro de duas requisições
     * simultâneas passando por este check ao mesmo tempo).
     *
     * @throws DuplicateOrderNumberException
     */
    public function assertNumberIsAvailable(int $number): void
    {
        if (Order::where('number', $number)->exists()) {
            throw new DuplicateOrderNumberException;
        }
    }
}
