<?php

namespace Tests\Feature\Modules;

use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `OrderStatus::derivedFromEquipments()`/`isAutomaticOnlyTransition()` (api#140) são funções
 * puras do Domain — testadas direto, sem HTTP/agregado/banco (esses cenários já são cobertos de
 * ponta a ponta em OrdersHttpTest/OrdersAggregateTest).
 */
class OrderStatusTest extends TestCase
{
    public function test_approved_with_one_resolved_equipment_and_others_pending_derives_partially_completed(): void
    {
        $derived = OrderStatus::Approved->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::InAnalysis,
        ]);

        $this->assertSame(OrderStatus::PartiallyCompleted, $derived);
    }

    public function test_approved_with_all_equipments_resolved_derives_completed(): void
    {
        $derived = OrderStatus::Approved->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::ReturnedUnrepaired,
        ]);

        $this->assertSame(OrderStatus::Completed, $derived);
    }

    public function test_approved_with_no_equipment_resolved_yet_does_not_derive(): void
    {
        $derived = OrderStatus::Approved->derivedFromEquipments([
            OrderEquipmentSituation::InAnalysis,
            OrderEquipmentSituation::AwaitingPart,
        ]);

        $this->assertNull($derived);
    }

    public function test_partially_completed_with_all_equipments_resolved_derives_completed(): void
    {
        $derived = OrderStatus::PartiallyCompleted->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::Completed,
        ]);

        $this->assertSame(OrderStatus::Completed, $derived);
    }

    /**
     * Caso raro (um equipamento que já tinha concluído volta a ficar pendente, correção manual
     * de situação) — a OS acompanha de volta pra approved, porque não existe "resolvido de
     * novo" sem ninguém concluído.
     */
    public function test_partially_completed_with_no_equipment_resolved_anymore_derives_approved(): void
    {
        $derived = OrderStatus::PartiallyCompleted->derivedFromEquipments([
            OrderEquipmentSituation::InAnalysis,
            OrderEquipmentSituation::AwaitingPart,
        ]);

        $this->assertSame(OrderStatus::Approved, $derived);
    }

    public function test_partially_completed_still_partial_does_not_derive(): void
    {
        $derived = OrderStatus::PartiallyCompleted->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::InAnalysis,
        ]);

        $this->assertNull($derived);
    }

    /**
     * Garantia: só o equipamento reaberto interessa — os outros já são completed (não passam por
     * aqui de novo). A OS só volta pra completed quando ele também resolver.
     */
    public function test_warranty_repair_with_the_reopened_equipment_still_pending_does_not_derive(): void
    {
        $derived = OrderStatus::WarrantyRepair->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::InAnalysis,
        ]);

        $this->assertNull($derived);
    }

    public function test_warranty_repair_with_everything_resolved_again_derives_completed(): void
    {
        $derived = OrderStatus::WarrantyRepair->derivedFromEquipments([
            OrderEquipmentSituation::Completed,
            OrderEquipmentSituation::Completed,
        ]);

        $this->assertSame(OrderStatus::Completed, $derived);
    }

    /**
     * @return list<OrderStatus>
     */
    public static function statusesThatDoNotDeriveFromEquipments(): array
    {
        return [
            [OrderStatus::Open],
            [OrderStatus::InAnalysis],
            [OrderStatus::AwaitingApproval],
            [OrderStatus::NotApproved],
            [OrderStatus::Completed],
            [OrderStatus::Canceled],
        ];
    }

    #[DataProvider('statusesThatDoNotDeriveFromEquipments')]
    public function test_statuses_outside_approved_partially_completed_and_warranty_repair_never_derive(OrderStatus $status): void
    {
        $derived = $status->derivedFromEquipments([OrderEquipmentSituation::Completed, OrderEquipmentSituation::InAnalysis]);

        $this->assertNull($derived);
    }

    public function test_derivation_with_no_equipments_does_not_derive(): void
    {
        $this->assertNull(OrderStatus::Approved->derivedFromEquipments([]));
    }

    public function test_entering_partially_completed_manually_is_automatic_only(): void
    {
        $this->assertTrue(OrderStatus::Approved->isAutomaticOnlyTransition(OrderStatus::PartiallyCompleted));
    }

    public function test_leaving_partially_completed_back_to_approved_is_automatic_only(): void
    {
        $this->assertTrue(OrderStatus::PartiallyCompleted->isAutomaticOnlyTransition(OrderStatus::Approved));
    }

    public function test_completing_from_partially_completed_is_not_automatic_only(): void
    {
        $this->assertFalse(OrderStatus::PartiallyCompleted->isAutomaticOnlyTransition(OrderStatus::Completed));
    }

    public function test_partially_completed_has_no_stalled_alert_milestones(): void
    {
        $this->assertSame([], OrderStatus::PartiallyCompleted->stalledAlertMilestoneDays());
    }

    public function test_approved_can_transition_to_partially_completed_and_completed(): void
    {
        $this->assertTrue(OrderStatus::Approved->canTransitionTo(OrderStatus::PartiallyCompleted));
        $this->assertTrue(OrderStatus::Approved->canTransitionTo(OrderStatus::Completed));
    }

    public function test_partially_completed_can_transition_to_completed_and_back_to_approved(): void
    {
        $this->assertTrue(OrderStatus::PartiallyCompleted->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue(OrderStatus::PartiallyCompleted->canTransitionTo(OrderStatus::Approved));
    }

    public function test_completed_and_returned_unrepaired_are_the_only_resolved_equipment_situations(): void
    {
        $this->assertTrue(OrderEquipmentSituation::Completed->isResolved());
        $this->assertTrue(OrderEquipmentSituation::ReturnedUnrepaired->isResolved());
        $this->assertFalse(OrderEquipmentSituation::InAnalysis->isResolved());
        $this->assertFalse(OrderEquipmentSituation::AwaitingPart->isResolved());
        $this->assertFalse(OrderEquipmentSituation::ExternalRepair->isResolved());
    }
}
