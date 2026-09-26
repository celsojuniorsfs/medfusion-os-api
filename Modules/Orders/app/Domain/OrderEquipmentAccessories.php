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

        // Ignora silenciosamente um item malformado (não-array, ou sem name/quantity) em vez de
        // estourar TypeError — isso roda dentro de event-sourcing:replay, que processa TODOS os
        // agregados numa passada só; deixar um item ruim de UM evento abortar o replay inteiro é
        // pior do que só pular aquele item (mesma classe de problema documentada em CLAUDE.md pros
        // api#101/api#108 — o sintoma só aparece no replay, não na leitura normal).
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
