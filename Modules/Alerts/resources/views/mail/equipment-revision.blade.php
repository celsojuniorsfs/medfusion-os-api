<x-mail::message>
@if ($milestone->value === 'month_6')
# Acompanhamento: {{ $cycle['equipment_name'] }}

Já se passaram 6 meses desde a última manutenção preventiva do equipamento **{{ $cycle['equipment_name'] }}**
(OS **{{ $cycle['order_number'] }}**). Bom momento pra ver como o cliente está e se precisa de algo.
@else
# Revisão anual próxima: {{ $cycle['equipment_name'] }}

O equipamento **{{ $cycle['equipment_name'] }}** (OS **{{ $cycle['order_number'] }}**) completa 12 meses
da última manutenção preventiva em **{{ $revisionDueDate->translatedFormat('d/m/Y') }}**. Hora de agendar a revisão.
@endif

Cliente: {{ $cycle['client_name'] }}

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/orders/' . $cycle['order_id']">
Ver ordem de serviço
</x-mail::button>

Med Fusion
</x-mail::message>
