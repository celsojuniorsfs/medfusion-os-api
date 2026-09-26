<?php

namespace Tests\Feature\Modules;

use Modules\Orders\Domain\OrderEquipmentAccessories;
use Tests\TestCase;

class OrderEquipmentAccessoriesTest extends TestCase
{
    public function test_splits_legacy_text_on_comma_and_semicolon(): void
    {
        $this->assertSame(
            [['name' => 'Cabo de força', 'quantity' => 1], ['name' => 'pedal', 'quantity' => 1], ['name' => 'manual', 'quantity' => 1]],
            OrderEquipmentAccessories::fromLegacyText('Cabo de força; pedal, manual'),
        );
    }

    public function test_trims_and_drops_blank_pieces(): void
    {
        $this->assertSame(
            [['name' => 'Cabo', 'quantity' => 1]],
            OrderEquipmentAccessories::fromLegacyText('  Cabo  ,  ,  ; '),
        );
    }

    public function test_empty_string_yields_an_empty_list(): void
    {
        $this->assertSame([], OrderEquipmentAccessories::fromLegacyText(''));
        $this->assertSame([], OrderEquipmentAccessories::fromLegacyText('   '));
    }

    public function test_normalize_passes_an_array_through_with_int_quantity(): void
    {
        $this->assertSame(
            [['name' => 'Pedal', 'quantity' => 2]],
            OrderEquipmentAccessories::normalize([['name' => ' Pedal ', 'quantity' => '2']]),
        );
    }

    public function test_normalize_null_yields_an_empty_list(): void
    {
        $this->assertSame([], OrderEquipmentAccessories::normalize(null));
    }
}
