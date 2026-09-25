<?php

namespace Modules\Clients\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * GET /cep/{cep} — proxy pro ViaCEP (gratuito, sem autenticação) com cache. Consulta a um
 * serviço externo, não estado de domínio nosso — por isso fica fora do padrão event-sourced do
 * resto do módulo, sem invalidação por evento (só o TTL longo).
 */
class CepController
{
    public function show(string $cep): JsonResponse
    {
        $digits = preg_replace('/\D/', '', $cep);

        Validator::make(['cep' => $digits], ['cep' => ['required', 'digits:8']])->validate();

        // TTL de 30 dias — dado de referência externo, quase estático. Devolve o mesmo shape do
        // ViaCEP sem reformatar.
        $data = Cache::remember(
            "cep:{$digits}",
            now()->addDays(30),
            fn () => Http::timeout(5)->get("https://viacep.com.br/ws/{$digits}/json/")->throw()->json(),
        );

        return response()->json($data);
    }
}
