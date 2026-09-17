<?php

// Bootstrap customizado só por causa do OTEL_SDK_DISABLED (ver CLAUDE.md § OpenTelemetry).
//
// O PHPUnit converte os literais "true"/"false" de <env> em bool nativo do PHP antes de
// reexportar pro processo via putenv() — e (string) true é "1". O parser do SDK OTel só aceita os
// literais "true"/"false" (case-insensitive); ao ver "1" ele loga "Invalid boolean value" e trata
// como desabilitado=false, ou seja, como se o SDK estivesse LIGADO. O keepsuit/laravel-opentelemetry
// tenta corrigir isso sincronizando o valor de volta via Env::getRepository()->set() durante o
// boot, mas isso só atualiza o repositório interno do Dotenv (o que o env()/config() do Laravel
// leem) — não o getenv() bruto do processo, que é o que Sdk::isDisabled() de fato consulta. Com o
// SDK "achando" que está ligado, ele registra instrumentação de verdade (HTTP, queries, cache,
// console) em cima de toda a suíte, e cada teste paga o custo disso — foi o que fez a suíte
// inteira ficar ~10x mais lenta ao instalar o pacote.
//
// putenv() aqui, antes de qualquer autoload, garante que a string exata "true" chega intacta no
// getenv() bruto, sem passar pela conversão do PHPUnit.
putenv('OTEL_SDK_DISABLED=true');

require __DIR__.'/../vendor/autoload.php';
