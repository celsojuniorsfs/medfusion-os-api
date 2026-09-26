@php
    use Modules\Orders\Presentation\Pdf\OrderPdfFormatter as Fmt;

    $company = config('company');
    $client = $order->client;
    $taxIdLabel = $client?->person_type === 'individual' ? 'CPF' : 'CNPJ';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 24px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 3px 4px; vertical-align: top; }
    .label { color: #555; font-size: 9px; text-transform: uppercase; }
    .header-table td { padding: 0; vertical-align: middle; }
    .logo { width: 90px; }
    .company-name { font-weight: bold; font-size: 17px; color: #1b6e6e; margin-bottom: 4px; }
    .company-details { font-size: 11px; color: #555; line-height: 1.6; }
    .order-number { color: #c0392b; font-weight: bold; font-size: 14px; }
    .section-title { background: #1b6e6e; color: #fff; font-weight: bold; padding: 4px 6px; margin-top: 8px; }
    .bordered { border: 1px solid #999; }
    .bordered td { border: 1px solid #999; }
    .checkbox-table td { text-align: left; }
    .checkbox { display: inline-block; width: 10px; height: 10px; border: 1px solid #333; text-align: center; line-height: 10px; margin-right: 4px; }
    .checkbox.checked { background: #1b6e6e; color: #fff; }
    .equipment-table { table-layout: fixed; }
    .equipment-table th { background: #eee; text-align: left; }
    .equipment-table tr { page-break-inside: avoid; }
    .equipment-table .accessories-cell { padding: 0 4px 4px 24px; }
    .equipment-table .accessories-cell div { font-size: 10px; color: #444; }
    .items-table { table-layout: fixed; }
    .items-table th { text-align: left; }
    .footer-table { table-layout: fixed; }
    .footer-table td { background: #1b6e6e; color: #fff; font-weight: bold; }
    .footer-table .value { background: #fff; color: #1a1a1a; font-weight: normal; }
    .footer-wrap { table-layout: fixed; }
    .observation { margin-top: 6px; font-style: italic; }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td class="logo"><img src="{{ $logoBase64 }}" width="90"></td>
        <td>
            <div class="company-name">{{ $company['name'] }}</div>
            <div class="company-details">
                {{ $company['address'] }} — CEP: {{ $company['postal_code'] }}<br>
                CNPJ: {{ $company['tax_id'] }} — {{ $company['phone'] }} / WhatsApp {{ $company['whatsapp'] }}
            </div>
        </td>
        <td style="text-align: right; width: 140px;">
            <div class="label">Data</div>
            <div>{{ $order->date?->format('d/m/Y') }}</div>
            <div class="label" style="margin-top: 4px;">Orçamento</div>
            <div class="order-number">{{ $order->number }}</div>
        </td>
    </tr>
</table>

<table class="bordered" style="margin-top: 8px;">
    <tr>
        <td style="width: 90px;"><span class="label">Cliente</span></td>
        <td colspan="3">{{ $client?->name }}</td>
    </tr>
    <tr>
        <td><span class="label">{{ $taxIdLabel }}</span></td>
        <td>{{ Fmt::taxId($client?->tax_id) }}</td>
        <td style="width: 90px;"><span class="label">Setor</span></td>
        <td>{{ $client?->department }}</td>
    </tr>
    <tr>
        <td><span class="label">Solicitante</span></td>
        <td>{{ $client?->requester }}</td>
        <td><span class="label">Telefone</span></td>
        <td>{{ $client?->phone }}</td>
    </tr>
    <tr>
        <td><span class="label">Endereço</span></td>
        <td colspan="3">{{ $client?->address }}</td>
    </tr>
    <tr>
        <td><span class="label">Cidade</span></td>
        <td>{{ $client?->city }}{{ $client?->state ? ' - '.$client->state : '' }}</td>
        <td><span class="label">CEP</span></td>
        <td>{{ Fmt::cep($client?->postal_code) }}</td>
    </tr>
</table>

<table class="checkbox-table" style="margin-top: 6px;">
    <tr>
        <td><span class="checkbox {{ $order->picked_up ? 'checked' : '' }}">{{ $order->picked_up ? 'X' : '' }}</span> RETIRADO</td>
        <td><span class="checkbox {{ $order->warranty ? 'checked' : '' }}">{{ $order->warranty ? 'X' : '' }}</span> GARANTIA</td>
        <td><span class="checkbox {{ $order->technical_training ? 'checked' : '' }}">{{ $order->technical_training ? 'X' : '' }}</span> TREINAMENTO TÉCNICO</td>
    </tr>
    <tr>
        <td><span class="checkbox {{ $order->on_site_quote ? 'checked' : '' }}">{{ $order->on_site_quote ? 'X' : '' }}</span> ORÇ. LOCAL</td>
        <td><span class="checkbox {{ $order->rental ? 'checked' : '' }}">{{ $order->rental ? 'X' : '' }}</span> LOCAÇÃO</td>
        <td></td>
    </tr>
</table>

<div class="section-title">EQUIPAMENTOS</div>
<table class="bordered equipment-table">
    <colgroup>
        <col style="width: 30%;">
        <col style="width: 18%;">
        <col style="width: 18%;">
        <col style="width: 20%;">
        <col style="width: 14%;">
    </colgroup>
    <thead>
        <tr>
            <th>Equipamento</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>N/S</th>
            <th>PAT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->equipments as $equipment)
            <tr>
                <td>{{ $equipment->name }}</td>
                <td>{{ $equipment->brand }}</td>
                <td>{{ $equipment->model }}</td>
                <td>{{ $equipment->serial_number }}</td>
                <td>{{ $equipment->asset_tag }}</td>
            </tr>
            @if ($equipment->accessories->isNotEmpty())
                <tr>
                    <td colspan="5" class="accessories-cell">
                        @foreach ($equipment->accessories as $accessory)
                            <div>{{ $accessory->quantity }}x {{ $accessory->name }}</div>
                        @endforeach
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

<table style="margin-top: 6px;">
    <tr>
        <td style="width: 120px;"><span class="label">Defeito apresentado</span></td>
        <td>{{ $order->reported_defect }}</td>
    </tr>
    <tr>
        <td><span class="label">Manutenção a aplicar</span></td>
        <td>{{ $order->maintenance_plan }}</td>
    </tr>
</table>

@if ($order->notes)
    <div class="observation">Observação: {{ $order->notes }}</div>
@endif

<div class="section-title" style="margin-top: 8px;">PEÇAS REPOSIÇÃO</div>
<table class="bordered items-table">
    <colgroup>
        <col style="width: 60px;">
        <col>
        <col style="width: 90px;">
    </colgroup>
    <thead>
        <tr>
            <th>Quant.</th>
            <th>Descrição</th>
            <th>Valor</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($order->items as $item)
            <tr>
                <td>{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->unit_price !== null ? Fmt::currency($item->unit_price) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="color: #999;">Sem peças nesta OS.</td></tr>
        @endforelse
    </tbody>
</table>

<!--
    Duas mini-tabelas lado a lado, cada uma com sua própria altura de linha — não uma tabela só
    de 4 colunas. Forma pagamento/Garantia (e Validade proposta/Mão de obra) são campos de texto
    livre sem relação de tamanho um com o outro; numa tabela só, um valor longo de um lado (ex.:
    "Garantia de 90 dias para peças e 180 dias para serviço...") estica a LINHA inteira e deixa o
    lado curto (ex.: "Boleto bancário") com um vão vazio enorme embaixo — achado renderizando um
    PDF de verdade com esses dois campos de tamanhos bem diferentes.

    `table-layout: fixed` + `colgroup`, não só `width` no <td>: sem isso, o dompdf auto-dimensiona
    colunas pelo conteúdo, e um valor comprido de um lado empurrava a coluna do vizinho.

    Cada valor tem um `?: '—'`: uma célula SEM NENHUM conteúdo (string vazia, não um espaço) não
    ganha caixa nenhuma pro dompdf pintar o fundo — a célula do rótulo (teal) ao lado, essa sim com
    conteúdo, escala e cobre o espaço que deveria ser da célula de valor. Só apareceu testando um
    PDF de verdade com campo opcional em branco (Forma pagamento/Garantia/Validade proposta —
    nenhum dos três é obrigatório no formulário).
-->
<table class="footer-wrap" style="margin-top: 8px;">
    <colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>
    <tr>
        <td style="padding: 0 4px 0 0;">
            <table class="footer-table">
                <colgroup><col style="width: 45%;"><col style="width: 55%;"></colgroup>
                <tr><td>Forma pagamento</td><td class="value">{{ $order->payment_method ?: '—' }}</td></tr>
                <tr><td>Validade proposta</td><td class="value">{{ $order->proposal_validity ?: '—' }}</td></tr>
            </table>
        </td>
        <td style="padding: 0 0 0 4px;">
            <table class="footer-table">
                <colgroup><col style="width: 45%;"><col style="width: 55%;"></colgroup>
                <tr><td>Garantia</td><td class="value">{{ $order->warranty_period ?: '—' }}</td></tr>
                <tr><td>Mão de obra</td><td class="value">{{ Fmt::currency($order->labor_cost) }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table class="footer-table" style="margin-top: 4px;">
    <colgroup><col style="width: 85%;"><col style="width: 15%;"></colgroup>
    <tr>
        <td style="text-align: right;">Total</td>
        <td class="value">{{ Fmt::currency($order->total) }}</td>
    </tr>
</table>

</body>
</html>
