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
    .header-table td { padding: 0; }
    .logo { width: 90px; }
    .company-name { font-weight: bold; font-size: 13px; color: #1b6e6e; }
    .company-details { font-size: 9px; color: #555; }
    .order-number { color: #c0392b; font-weight: bold; font-size: 14px; }
    .section-title { background: #1b6e6e; color: #fff; font-weight: bold; padding: 4px 6px; margin-top: 8px; }
    .bordered { border: 1px solid #999; }
    .bordered td { border: 1px solid #999; }
    .checkbox-table td { text-align: left; }
    .checkbox { display: inline-block; width: 10px; height: 10px; border: 1px solid #333; text-align: center; line-height: 10px; margin-right: 4px; }
    .checkbox.checked { background: #1b6e6e; color: #fff; }
    .equipment-table th { background: #eee; text-align: left; }
    .equipment-table tr { page-break-inside: avoid; }
    .footer-table td { background: #1b6e6e; color: #fff; font-weight: bold; }
    .footer-table .value { background: #fff; color: #1a1a1a; font-weight: normal; }
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
    <thead>
        <tr>
            <th>Equipamento</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>N/S</th>
            <th>PAT</th>
            <th>Acessórios</th>
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
                <td>{{ $equipment->accessories }}</td>
            </tr>
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
<table class="bordered">
    <thead>
        <tr>
            <th style="width: 60px;">Quant.</th>
            <th>Descrição</th>
            <th style="width: 90px;">Valor</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->unit_price !== null ? Fmt::currency($item->unit_price) : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="footer-table" style="margin-top: 8px;">
    <tr>
        <td style="width: 25%;">Forma pagamento</td>
        <td class="value" style="width: 25%;">{{ $order->payment_method }}</td>
        <td style="width: 25%;">Garantia</td>
        <td class="value" style="width: 25%;">{{ $order->warranty_period }}</td>
    </tr>
    <tr>
        <td>Validade proposta</td>
        <td class="value">{{ $order->proposal_validity }}</td>
        <td>Mão de obra</td>
        <td class="value">{{ Fmt::currency($order->labor_cost) }}</td>
    </tr>
    <tr>
        <td colspan="3" style="text-align: right;">Total</td>
        <td class="value">{{ Fmt::currency($order->total) }}</td>
    </tr>
</table>

</body>
</html>
