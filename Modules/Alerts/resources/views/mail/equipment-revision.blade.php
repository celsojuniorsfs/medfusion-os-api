<x-mail::message>
@if ($milestone->value === 'month_6')
# Acompanhamento: {{ $equipmentName }}

Já se passaram 6 meses desde a última manutenção preventiva do equipamento **{{ $equipmentName }}**
(OS **{{ $cycle['order_number'] }}**). Bom momento pra ver como o cliente está e se precisa de algo.
@else
# Revisão anual próxima: {{ $equipmentName }}

O equipamento **{{ $equipmentName }}** (OS **{{ $cycle['order_number'] }}**) completa 12 meses
da última manutenção preventiva em **{{ $revisionDueDate->translatedFormat('d/m/Y') }}**. Hora de agendar a revisão.
@endif

Cliente: {{ $cycle['client_name'] }}

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/orders/' . $cycle['order_id']">
Ver ordem de serviço
</x-mail::button>

Med Fusion
</x-mail::message>
