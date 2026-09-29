<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Orçamento por equipamento (api#149) — nullable de propósito: null = nenhum orçamento
        // gerado ainda pra este equipamento (ver RecordOrderPdf), diferente de qualquer um dos
        // três valores de OrderEquipmentApprovalStatus.
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->string('approval_status')->nullable()->after('calibration');
            $table->timestamp('approval_status_changed_at')->nullable()->after('approval_status');
            $table->decimal('labor_cost', 10, 2)->nullable()->after('approval_status_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approval_status_changed_at', 'labor_cost']);
        });
    }
};
