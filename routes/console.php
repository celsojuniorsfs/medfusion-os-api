<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// api#135 — grava os marcos vencidos pro painel de alertas do front-end (api#158, sem e-mail).
// Isto só dispara de verdade se algo rodar `schedule:run` periodicamente — conferir/configurar
// isso no painel da Laravel Cloud é passo de implantação separado (ver docs/ambientes.md §
// Scheduler, a preencher).
Schedule::command('orders:check-stalled')->daily()->withoutOverlapping();

// api#147 — comando próprio, escada de marcos diferente da de cima.
Schedule::command('orders:check-equipment-situations')->daily()->withoutOverlapping();

// api#136 — mesmo padrão, marcos em meses (6/11) em vez de dias.
Schedule::command('alerts:check-equipment-revisions')->daily()->withoutOverlapping();
