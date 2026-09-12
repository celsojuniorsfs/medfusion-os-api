{{--
    Card "Servers" removido: exige o daemon `pulse:check` rodando no servidor, que não temos (nem
    localmente nem no Laravel Cloud, onde o container é efêmero e gerenciado pela plataforma) — ver
    docs/architecture.md.
--}}
<x-pulse>
    <livewire:pulse.usage cols="4" rows="2" />

    <livewire:pulse.queues cols="4" />

    <livewire:pulse.cache cols="4" />

    <livewire:pulse.slow-queries cols="8" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />
</x-pulse>
