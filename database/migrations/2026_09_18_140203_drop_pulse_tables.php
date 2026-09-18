<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Pulse removido em 18/09/2026 (substituído por OpenTelemetry → Grafana Cloud, ver
 * docs/architecture.md § Monitoramento). A migration de criação
 * (2026_09_12_145918_create_pulse_tables.php) foi apagada junto com o pacote — mas as tabelas já
 * existem em produção e no banco de todo mundo que já rodou `migrate`; só apagar o arquivo não as
 * removeria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pulse_aggregates');
        Schema::dropIfExists('pulse_entries');
        Schema::dropIfExists('pulse_values');
    }

    public function down(): void
    {
        // Sem volta: as definições originais dependiam de `Laravel\Pulse\Support\PulseMigration`,
        // que saiu com o pacote. Remoção é de mão única, de propósito.
    }
};
