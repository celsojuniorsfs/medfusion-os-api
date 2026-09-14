<?php

namespace Modules\Clients\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * GET /cep/{cep} — proxy pro ViaCEP (gratuito, sem autenticação) com cache. Sem
 * aggregate/evento de propósito: não é estado de domínio nosso, é uma consulta a um serviço
 * externo (ver docs/architecture.md § Cache) — por isso mora aqui como um controller simples,
 * fora do padrão event-sourced do resto do módulo, e usa `Cache::remember` (sem tag): não há
 * evento nosso que invalide isto, só o TTL longo, já que endereço de CEP não muda por causa de
 * nada que a Med Fusion faça.
 */
class CepController
{
    public function show(string $cep): JsonResponse
    {
        $digits = preg_replace('/\D/', '', $cep);

        Validator::make(['cep' => $digits], ['cep' => ['required', 'digits:8']])->validate();

        // TTL de 30 dias — dado de referência externo, quase estático. Devolve o mesmo shape do
        // ViaCEP (logradouro, localidade, uf, erro, ...) sem reformatar: o frontend já sabe ler
        // esse formato (era o que chamava viacep.com.br direto antes deste proxy existir).
        $data = Cache::remember(
            "cep:{$digits}",
            now()->addDays(30),
            fn () => Http::timeout(5)->get("https://viacep.com.br/ws/{$digits}/json/")->throw()->json(),
        );

        return response()->json($data);
    }
}
