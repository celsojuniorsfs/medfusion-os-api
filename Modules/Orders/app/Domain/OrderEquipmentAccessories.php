<?php

namespace Modules\Orders\Domain;

/**
 * Normaliza o payload de acessórios de um equipamento da OS pra lista de {name, quantity}.
 *
 * accessories já foi texto livre (?string) — eventos antigos em stored_events (e requests de
 * clientes ainda não atualizados) ainda chegam nesse formato. `normalize()` é o único lugar que
 * decide como dividir esse texto; nunca duplicar essa regra.
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

        // Ignora silenciosamente um item malformado em vez de estourar TypeError — isso roda
        // dentro de event-sourcing:replay, que processa todos os agregados numa passada só; um
        // item ruim de UM evento não pode abortar o replay inteiro.
        $normalized = [];
        foreach ($accessories as $accessory) {
            if (! is_array($accessory) || ! isset($accessory['name'], $accessory['quantity'])) {
                continue;
            }

            $name = trim((string) $accessory['name']);
            if ($name === '') {
                continue;
            }

            $normalized[] = ['name' => $name, 'quantity' => (int) $accessory['quantity']];
        }

        return $normalized;
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
