<x-mail::message>
# OS {{ $order->number }}: {{ $equipment->name }} parado há {{ $milestoneDays }} dias

O equipamento **{{ $equipment->name }}** da ordem de serviço **{{ $order->number }}** está na
situação **{{ $equipment->situation }}** há {{ $milestoneDays }} dias sem mudar.

Cliente: {{ $order->client?->name }}

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/orders/' . $order->id">
Ver ordem de serviço
</x-mail::button>

Med Fusion
</x-mail::message>
