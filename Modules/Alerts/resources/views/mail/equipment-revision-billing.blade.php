<x-mail::message>
# Cobrança: revisão sem contato

O aviso de {{ $alert->milestone === 'month_6' ? 'acompanhamento (mês 6)' : 'revisão anual (mês 11)' }}
do equipamento **{{ $alert->equipment->name }}** (OS **{{ $order->number }}**) foi enviado há mais de
7 dias e ninguém marcou "cliente contatado".

Cliente: {{ $order->client?->name }}

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/orders/' . $order->id">
Ver ordem de serviço
</x-mail::button>

Med Fusion
</x-mail::message>
