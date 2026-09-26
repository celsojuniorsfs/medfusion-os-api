<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_equipments.accessories (texto livre) foi substituído por order_equipment_accessories (uma
 * linha por acessório, name + quantity) — ver a migration de backfill, que já copiou o texto
 * existente pra lá antes desta rodar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->dropColumn('accessories');
        });
    }

    public function down(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->string('accessories')->nullable();
        });
    }
};
