<?php

namespace Modules\Alerts\Domain\Enums;

enum RevisionMilestone: string
{
    case Month6 = 'month_6';
    case Month11 = 'month_11';

    public function months(): int
    {
        return match ($this) {
            self::Month6 => 6,
            self::Month11 => 11,
        };
    }
}
