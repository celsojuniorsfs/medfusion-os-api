<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api#92 — acessórios deixa de ser texto livre e vira uma lista estruturada, ligada ao catálogo
 * global (ver create_equipment_accessories_table, logo em seguida). O texto já cadastrado não é
 * preservado (decisão do próprio pedido): a coluna some, sem migração de dado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropColumn('accessories');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->string('accessories')->nullable();
        });
    }
};
