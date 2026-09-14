# Arquitetura

> Decidido em 08/09/2026, antes de qualquer CRUD ser escrito (a Fase 4 tinha entregado só o
> esqueleto: models Eloquent "nus", migrations, auth Sanctum). Complementa
> [`api-conventions.md`](./api-conventions.md) (autenticação, formato de erro) e
> [`openapi.yaml`](./openapi.yaml) (contrato).

## Em uma frase

Monólito modular, DDD-like, Clean Architecture — e para não precisar criar camadas de abstração
(interfaces de repositório, portas/adaptadores) só para isolar os módulos, **os eventos de
domínio são o mecanismo de integração entre módulos**, via
[`spatie/laravel-event-sourcing`](https://spatie.be/docs/laravel-event-sourcing/v7/) (v7). A
divisão em módulos (habilitar/desabilitar, autoload, service provider por módulo) usa
[`nwidart/laravel-modules`](https://laravelmodules.com/) (v13) — cuidamos da nossa própria
camada de domínio dentro de cada módulo; o pacote só cuida do "empacotamento".

## Módulos

`Identity`, `Clients`, `Equipments`, `Orders` — um por conceito de negócio, não por camada
técnica. Cada um é um módulo nwidart em `Modules/<Módulo>/` (`module.json`, `composer.json`
próprio, mesclado no autoload raiz via `wikimedia/composer-merge-plugin`). Dentro de
`Modules/<Módulo>/app/` seguimos Clean Architecture, de dentro para fora:

```
Modules/<Módulo>/
  app/
    Domain/          Agregado (AggregateRoot), Events/, Enums/, Exceptions/ — zero Laravel,
                     exceto a própria classe base do spatie (o preço combinado por não abstrair)
    Application/     Actions invocáveis que orquestram um caso de uso (sem command bus)
    Infrastructure/  Projectors/, Reactors/, ReadModels/ (Eloquent) — o lado de leitura
    Presentation/    Http/Controllers, Http/Requests, Http/Resources
    Providers/       <Módulo>ServiceProvider.php (extends ModuleServiceProvider), RouteServiceProvider
  routes/api.php      Só nos módulos com endpoint HTTP (hoje todos: Identity, Clients,
                      Equipments e Orders)
  database/
    migrations/       Descobertas automaticamente (auto-discover.migrations, config/modules.php)
    seeders/
  module.json
  composer.json        psr-4 "Modules\<Módulo>\": "app/" — namespace raiz do módulo
```

Namespace raiz de cada módulo é `Modules\<Módulo>\` (convenção do pacote — não `App\Modules\`),
mapeado para a pasta `app/` do módulo pelo `composer.json` gerado por `php artisan module:make`.

Regra de dependência: `Domain` ← `Application` ← `Infrastructure` / `Presentation`.

## A regra que evita as abstrações — e onde ela para

**Domain e Application de um módulo nunca importam Domain/Application/Infrastructure de outro
módulo.** A única forma de um módulo referenciar outro é por **uuid** (passado como parâmetro
primitivo — nunca a classe do agregado) ou pelo **nome de uma classe de evento**
(`Modules\{Outro}\Domain\Events\...`, para uma Reactor futura reagir a algo que aconteceu em
outro módulo). Não existem interfaces de repositório, `Ports/`, nem `Contracts/` entre módulos —
é o preço que a Event Sourcing paga por nós: o próprio evento já é o contrato público.

Automatizado em `tests/Architecture/ModuleBoundariesTest.php`.

**Essa regra é do lado de escrita, não do lado de leitura.** `Infrastructure/ReadModels/` fica de
fora do teste de fronteira de propósito: os read models compõem relações Eloquent direto contra
o banco compartilhado entre módulos (ex.: `Order::client()` aponta para
`Modules\Clients\Infrastructure\ReadModels\Client`). É uma composição de consulta — não uma
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

**Exceção deliberada**: `OrderService` (api #44, `Modules/Orders/app/Application/OrderService.php`)
não segue esse formato — é uma consulta pura sobre o read model (sugestão de próximo número de OS,
checagem de número duplicado), sem agregado envolvido. Nome já decidido na análise do api #23
(ver `api-conventions.md`); fica em `Application/` por ser lógica de orquestração de negócio, não
por seguir o padrão de Actions.

## Projectors síncronos, Reactors em fila — registrados por módulo, sem auto-discovery

- **Projectors** (constroem os read models) rodam síncronos — o endpoint precisa devolver o
  recurso criado logo depois do `persist()`.
- **Reactors** (efeitos colaterais — notificação por e-mail/WhatsApp, geração de PDF, quando
  forem implementados) devem implementar `ShouldQueue`: um efeito colateral externo não pode
  segurar a resposta HTTP nem ser reexecutado durante um replay.
- O auto-discovery do spatie (`config/event-sourcing.php`) assume a convenção padrão do Laravel
  (namespace `App\` = pasta `app/`), que não bate com `Modules\<Módulo>\` = `Modules/<Módulo>/app/`
  de cada módulo. Em vez de reconfigurar o scanner por módulo, cada `<Módulo>ServiceProvider::boot()`
  registra explicitamente o seu: `Projectionist::addProjector(FooProjector::class)`.
- `php artisan event-sourcing:replay` reconstrói todos os read models a partir de
  `stored_events` — útil depois de corrigir um bug de projeção ou adicionar uma coluna nova.

## Cache — listagens em Valkey, invalidadas pelo Projector

Endpoints de listagem (`index`) cacheiam a resposta lida (`Cache::tags([...])->remember(...)`) —
`GET /clients`, `GET /clients/{id}/equipments`, `GET /orders`, `GET /accessories`. `show`/detalhe
fica de fora por enquanto.

Servidor real por trás do cache: **Valkey** (fork open-source do Redis mantido pela Linux
Foundation, mesmo protocolo RESP e mesmos comandos — criado depois da Redis Inc. mudar a licença
do Redis pra uma não-OSI em 2024). O Laravel não tem um driver "valkey" separado: `CACHE_STORE`
continua `redis` e as variáveis de ambiente continuam `REDIS_*` — é a nomenclatura do driver do
framework (amarrada ao protocolo, não ao software), não o nome do que roda de fato. O que muda
de nome é só a infraestrutura: o serviço `valkey` (antes `redis`) no `docker-compose.yml`.

- **Uma tag por módulo** (`'clients'`, `'equipments'`, `'orders'`, `'accessories'`), não por
  cliente/recurso individual — uma escrita em qualquer registro do módulo invalida a listagem
  inteira, mesmo de um cliente não afetado. `Cache::tags()` exige um store que suporte tags
  (o driver `redis` do Laravel suporta, seja o servidor Redis ou Valkey; `database`/`file` não)
  — é por isso que `CACHE_STORE=redis` deixou de ser opcional. Granularidade por cliente foi
  cogitada e descartada: alguns eventos (ex.: `EquipmentUpdated`/`EquipmentRemoved`) nem
  carregam o `client_id`, e listas por cliente são pequenas o bastante pra um cache miss a mais
  em clientes não afetados não pesar.
- **Chave de cache**: a URL completa da requisição (`sha1($request->fullUrl())`) quando o
  endpoint tem filtros/paginação (Clients, Orders) — cobre todos os parâmetros de uma vez, sem
  listar cada um manualmente. Equipments (sem filtro, só `client_id` na rota) usa só o id.
- **Invalidação orientada a evento, não TTL**: cada `Projector` do módulo chama
  `Cache::tags([...])->flush()` ao final de todo `on*` que ele já implementa — o Projector já é
  o único lugar que escreve no read model, então vira também o único lugar que invalida o cache
  dele. O `now()->addHour()` passado a `remember()` é só um limite de segurança, não a
  estratégia real de expiração.
- **Sem helper/trait compartilhado entre módulos**: é a mesma meia-dúzia de linhas repetida por
  módulo — `Cache` é uma facade do framework, não um import de módulo, então usá-la direto em
  cada um não fere a regra de fronteira acima, e este projeto não tem um "Modules/Shared" nem um
  `app/` raiz onde colocar algo assim sem inventar um lugar novo só pra isso.
- `phpunit.xml` roda a suíte com `CACHE_STORE=array` — os testes não dependem de um Valkey de
  verdade nem de tags (o store `array` ignora `Cache::tags()` silenciosamente, tratando como
  cache normal).

## Por que Event Sourcing (e não só "publicar eventos")

Duas coisas em uma: (1) `stored_events` é a fonte da verdade auditável de tudo que aconteceu com
uma OS (quem mudou o quê e quando — natural para uma ferramenta que substitui um controle manual
em Excel), e (2) os mesmos eventos que constroem a projeção são o que um módulo publica para
outro reagir, sem precisar inventar um "barramento de eventos" ou fila de integração separada —
o pacote já entrega os dois com a mesma peça (`ShouldBeStored` + `Projector`/`Reactor`).

## Monitoramento — Laravel Pulse é a exceção fora dos módulos

O dashboard em `/pulse` ([Laravel Pulse](https://laravel.com/docs/pulse), 12/09/2026) é um pacote
de vendor, não uma feature de negócio — não faz sentido forçá-lo dentro de `Modules/`. Duas
consequências dessa decisão:

- `resources/views/vendor/pulse/dashboard.blade.php` é o **único** Blade deste repo (que é
  API-only pro front Angular — ver `routes/web.php`); existe só porque o Pulse é uma tela
  server-rendered com Livewire, não porque o projeto voltou a servir HTML.
- O gate `Gate::define('viewPulse', ...)` mora em
  `Modules/Identity/app/Providers/IdentityServiceProvider.php::boot()`, e não em
  `app/Providers/AppServiceProvider.php` como a documentação oficial do Pulse sugere — este
  projeto não tem `app/` (removido de propósito, ver `bootstrap/providers.php`). Identity foi
  escolhido por ser o módulo dono de `User` e da autenticação, a mesma coisa que o gate decide
  sobre.

Card "Servers" (CPU/memória/disco) removido do dashboard: exige o daemon `pulse:check` rodando no
servidor, e o compute do Laravel Cloud é efêmero e gerenciado pela plataforma — não há o que medir
aí. Detalhes de acesso (Basic Auth + allowlist de e-mail) em `docs/ambientes.md` § Monitoramento.

## Comandos úteis do nwidart/laravel-modules

- `php artisan module:make <Nome> --api` — cria um módulo novo (só a parte de API; sem Blade).
- `php artisan module:list` — módulos e status (habilitado/desabilitado, `modules_statuses.json`).
- `php artisan module:make-migration <nome> <Módulo>` — nova migration dentro do módulo certo.
