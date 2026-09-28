<x-mail::message>
@if($autoRejected)
# OS {{ $order->number }} marcada como "Não aprovada"

A ordem de serviço **{{ $order->number }}** ficou {{ $milestoneDays }} dias em "Aguardando
aprovação" sem retorno do cliente e foi marcada automaticamente como **Não aprovada**.
@else
# OS {{ $order->number }} parada há {{ $milestoneDays }} dias

A ordem de serviço **{{ $order->number }}** está no status **{{ $order->status }}** há
{{ $milestoneDays }} dias sem mudar.
@endif

Cliente: {{ $order->client?->name }}

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/orders/' . $order->id">
Ver ordem de serviço
</x-mail::button>

Med Fusion
</x-mail::message>
