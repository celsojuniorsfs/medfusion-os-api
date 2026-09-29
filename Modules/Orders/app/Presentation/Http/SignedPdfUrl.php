<?php

namespace Modules\Orders\Presentation\Http;

use Illuminate\Support\Facades\URL;

/**
 * Link assinado de download de PDF (api#149), mesma janela de validade em
 * OrderPdfController::payload() (PDF atual) e OrderPdfHistoryResource (cada PDF do histórico).
 */
class SignedPdfUrl
{
    private const int TTL_MINUTES = 30;

    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array{url: string, expires_at: string}
     */
    public static function build(string $routeName, array $routeParameters): array
    {
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        return [
            'url' => URL::temporarySignedRoute($routeName, $expiresAt, $routeParameters),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
