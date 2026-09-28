<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Orders\Domain\Events\OrderStatusChanged;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        // Backfill: hora do OrderStatusChanged mais recente por OS (o "aggregate_version" mais
        // alto = a mudança de status mais recente); sem nenhum (nunca mudou de status desde a
        // abertura), cai no created_at da própria OS.
        DB::table('orders')->select('id', 'created_at')->orderBy('id')->each(function ($order) {
            $lastStatusChange = DB::table('stored_events')
                ->where('aggregate_uuid', $order->id)
                ->where('event_class', OrderStatusChanged::class)
                ->orderByDesc('aggregate_version')
                ->value('created_at');

            DB::table('orders')->where('id', $order->id)->update([
                'status_changed_at' => $lastStatusChange ?? $order->created_at,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
