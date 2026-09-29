<?php

namespace Modules\Orders\Domain\Enums;

/**
 * Status do orçamento de UM equipamento (api#149), independente de `OrderEquipmentSituation`.
 * A coluna em `order_equipments` é nullable — `null` = nenhum orçamento gerado ainda.
 */
enum OrderEquipmentApprovalStatus: string
{
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case NotApproved = 'not_approved';
}
