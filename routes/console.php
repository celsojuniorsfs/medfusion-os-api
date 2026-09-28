<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// api#135 — sem fila (não roda worker em produção): o próprio comando calcula e envia os
// e-mails, dentro da janela do cron. Isto só dispara de verdade se algo rodar `schedule:run`
// periodicamente — conferir/configurar isso no painel da Laravel Cloud é passo de implantação
// separado (ver docs/ambientes.md § Scheduler, a preencher).
Schedule::command('orders:check-stalled')->daily();
