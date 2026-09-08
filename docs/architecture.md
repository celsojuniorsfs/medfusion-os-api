# Arquitetura

> Decidido em 08/09/2026, antes de qualquer CRUD ser escrito (a Fase 4 tinha entregado só o
> esqueleto: models Eloquent "nus", migrations, auth Sanctum). Complementa
> [`api-conventions.md`](./api-conventions.md) (autenticação, formato de erro) e
> [`openapi.yaml`](./openapi.yaml) (contrato).

## Em uma frase

Monólito modular, DDD-like, Clean Architecture — e para não precisar criar camadas de abstração
(interfaces de repositório, portas/adaptadores) só para isolar os módulos, **os eventos de
domínio são o mecanismo de integração entre módulos**, via
[`spatie/laravel-event-sourcing`](https://spatie.be/docs/laravel-event-sourcing/v7/) (v7).

## Módulos

`Identity`, `Clients`, `Equipments`, `Orders` — um por conceito de negócio, não por camada
técnica. Cada um vive em `app/Modules/<Módulo>/` com quatro pastas (Clean Architecture, de
dentro para fora):

```
app/Modules/<Módulo>/
  Domain/          Agregado (AggregateRoot), Events/, Enums/, Exceptions/ — zero Laravel, exceto
                   a própria classe base do spatie (o preço combinado por não abstrair)
  Application/     Actions invocáveis que orquestram um caso de uso (sem command bus)
  Infrastructure/  Projectors/, Reactors/, ReadModels/ (Eloquent) — o lado de leitura
  Presentation/    Http/Controllers, Http/Requests, Http/Resources, routes.php
  <Módulo>ServiceProvider.php
```

Regra de dependência: `Domain` ← `Application` ← `Infrastructure` / `Presentation`.

## A regra que evita as abstrações — e onde ela para

**Domain e Application de um módulo nunca importam Domain/Application/Infrastructure de outro
módulo.** A única forma de um módulo referenciar outro é por **uuid** (passado como parâmetro
primitivo — nunca a classe do agregado) ou pelo **nome de uma classe de evento**
(`App\Modules\{Outro}\Domain\Events\...`, para uma Reactor futura reagir a algo que aconteceu em
outro módulo). Não existem interfaces de repositório, `Ports/`, nem `Contracts/` entre módulos —
é o preço que a Event Sourcing paga por nós: o próprio evento já é o contrato público.

Automatizado em `tests/Architecture/ModuleBoundariesTest.php`.

**Essa regra é do lado de escrita, não do lado de leitura.** `Infrastructure/ReadModels/` fica de
fora do teste de fronteira de propósito: os read models compõem relações Eloquent direto contra
o banco compartilhado entre módulos (ex.: `Order::client()` aponta para
`App\Modules\Clients\Infrastructure\ReadModels\Client`). É uma composição de consulta — não uma
decisão de domínio, não afeta nenhum agregado, e inventar uma camada de repositório só para
escondê-la seria exatamente a abstração desnecessária que esta arquitetura tenta evitar.

## Identidade: uuid do agregado = id do read model

Cada aggregate root usa uuid (mecanismo do spatie: `Aggregate::retrieve($uuid)`/`persist()`,
tabela `stored_events.aggregate_uuid`). O Projector cria a linha do read model com o **mesmo**
uuid como chave primária (`'id' => $event->aggregateRootUuid()`) — por isso é a identidade
pública da API (`GET /orders/{id}` usa esse uuid).

`orders.number` (seed 1336, editável pelo técnico) é um dado de negócio comum, sem relação com
identidade — nunca confundir os dois. O mesmo raciocínio vale para qualquer futuro identificador
de negócio (ex.: `certificate_number`).

## Agregados e eventos

| Módulo | Agregado | Eventos (hoje) |
|---|---|---|
| Identity | `UserAggregate` | `UserRegistered`, `UserPasswordChanged` |
| Clients | `ClientAggregate` | `ClientRegistered`, `ClientUpdated`, `ClientRemoved` |
| Equipments | `EquipmentAggregate` | `EquipmentRegistered`, `EquipmentUpdated`, `EquipmentRemoved` |
| Orders | `OrderAggregate` | `OrderOpened`, `OrderEquipmentAttached`, `OrderItemAdded`, `OrderStatusChanged` |

`OrderAggregate` também guarda `OrderEquipment` e `OrderItem` como parte do seu próprio stream de
eventos (são entidades internas do agregado Order, não agregados independentes — o snapshot de
equipamento decidido na F2 é literalmente o payload de `OrderEquipmentAttached`).

Login/logout **não** passam pelo agregado: são consulta ao read model + emissão/revogação de
token Sanctum (`AuthController`), sem gerar evento — não são decisões de domínio.

A máquina de 9 estados da OS (`docs/api-conventions.md` § Status da OS) mora em
`OrderStatus::allowedNextStatuses()` — o `OrderAggregate::changeStatus()` valida a transição e
lança `InvalidOrderStatusTransition` se ela não estiver na tabela.

Eventos futuros (fora desta sessão — CRUD, PDF e notificação ainda não existem):
`OrderPdfGenerated`, `OrderNotified`.

## Camada de orquestração — Actions, sem command bus

`Application/` tem classes invocáveis finas (`RegisterClient`, `OpenOrder`,
`ChangeOrderStatus`...), chamadas direto do controller — sem command bus, sem handlers
registrados em lugar nenhum. Cada Action: gera/recebe um uuid → `Aggregate::retrieve($uuid)` →
método de domínio → `persist()` → devolve o read model.

## Projectors síncronos, Reactors em fila

- **Projectors** (constroem os read models) rodam síncronos — o endpoint precisa devolver o
  recurso criado logo depois do `persist()`.
- **Reactors** (efeitos colaterais — notificação por e-mail/WhatsApp, geração de PDF, quando
  forem implementados) devem implementar `ShouldQueue`: um efeito colateral externo não pode
  segurar a resposta HTTP nem ser reexecutado durante um replay.
- Ambos são descobertos automaticamente pelo pacote (varre `app/` inteiro por classes que
  estendem `Projector`/`Reactor` — ver `vendor/spatie/laravel-event-sourcing/config/event-sourcing.php`,
  não precisou de config própria). Nenhum registro manual necessário.
- `php artisan event-sourcing:replay` reconstrói todos os read models a partir de
  `stored_events` — útil depois de corrigir um bug de projeção ou adicionar uma coluna nova.

## Por que Event Sourcing (e não só "publicar eventos")

Duas coisas em uma: (1) `stored_events` é a fonte da verdade auditável de tudo que aconteceu com
uma OS (quem mudou o quê e quando — natural para uma ferramenta que substitui um controle manual
em Excel), e (2) os mesmos eventos que constroem a projeção são o que um módulo publica para
outro reagir, sem precisar inventar um "barramento de eventos" ou fila de integração separada —
o pacote já entrega os dois com a mesma peça (`ShouldBeStored` + `Projector`/`Reactor`).
