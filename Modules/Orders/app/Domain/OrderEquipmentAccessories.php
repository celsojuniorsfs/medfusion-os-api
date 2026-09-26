<?php

namespace Modules\Orders\Domain;

/**
 * Normaliza o payload de acessórios de um equipamento da OS pra lista de {name, quantity}.
 *
 * Antes desta classe existir, accessories era texto livre (?string) — eventos já gravados em
 * stored_events (e requests de um cliente Angular ainda não atualizado, ver OrderRequest) ainda
 * chegam nesse formato. `normalize()` é o único lugar que decide como dividir esse texto; nunca
 * duplicar essa regra (a migration de backfill copia a lógica, de propósito, em vez de chamar esta
 * classe — ver o comentário lá).
 */
final class OrderEquipmentAccessories
{
    /**
     * @return array<int, array{name: string, quantity: int}>
     */
    public static function normalize(string|array|null $accessories): array
    {
        if ($accessories === null) {
            return [];
        }

        if (is_string($accessories)) {
            return self::fromLegacyText($accessories);
        }

        return array_values(array_map(
            fn (array $accessory) => [
                'name' => trim((string) $accessory['name']),
                'quantity' => (int) $accessory['quantity'],
            ],
            $accessories,
        ));
    }

    /**
     * @return array<int, array{name: string, quantity: int}>
     */
    public static function fromLegacyText(string $text): array
    {
        $names = array_filter(
            array_map('trim', preg_split('/[,;]/', $text)),
            fn (string $name) => $name !== '',
        );

        return array_values(array_map(
            fn (string $name) => ['name' => $name, 'quantity' => 1],
            $names,
        ));
    }
}
