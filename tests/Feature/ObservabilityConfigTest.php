<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Trava três decisões de config/opentelemetry.php que uma futura `vendor:publish --force` (num
 * upgrade do keepsuit/laravel-opentelemetry) derrubaria em silêncio, sem nenhum teste denunciar:
 * o escopo pedido é só métrica (requests HTTP + queries), nunca tracing/logs, e a suíte nunca pode
 * tentar sair para a rede. Ver docs/architecture.md § Monitoramento.
 */
class ObservabilityConfigTest extends TestCase
{
    public function test_traces_export_is_disabled(): void
    {
        // Default do pacote é 'otlp' — sem isto, traces seriam empurrados sem ninguém ter pedido.
        $this->assertSame('null', config('opentelemetry.traces.exporter'));
    }

    public function test_logs_export_is_disabled(): void
    {
        $this->assertSame('null', config('opentelemetry.logs.exporter'));
    }

    public function test_the_sdk_never_reaches_the_network_during_tests(): void
    {
        // phpunit.xml força OTEL_SDK_DISABLED=true — se isso regredir, os testes passam a fazer
        // I/O de rede de verdade a cada request.
        $this->assertTrue(config('opentelemetry.disabled'));
    }
}
