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

`Identity`, `Clients`, `Equipments`, `Orders`, `Accessories`, `EquipmentModels` — um por conceito
de negócio, não por camada
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
                      Equipments, Orders, Accessories e EquipmentModels)
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
| Equipments | `EquipmentAggregate` | `EquipmentRegistered`, `EquipmentUpdated`, `EquipmentRemoved`, `EquipmentPhotoAdded`, `EquipmentPhotoRemoved` |
| Orders | `OrderAggregate` | `OrderOpened`, `OrderEquipmentAttached`, `OrderItemAdded`, `OrderStatusChanged` |
| Accessories | `AccessoryAggregate` | `AccessoryRegistered`, `AccessoryUpdated`, `AccessoryRemoved` |
| EquipmentModels | `EquipmentModelAggregate` | `EquipmentModelRegistered`, `EquipmentModelUpdated`, `EquipmentModelRemoved` |

Os dois últimos são os **catálogos globais**. Nasceram append-only (api#92/#101), e foi essa ausência
de edição que permitiu a `equipments` guardar nome/marca/modelo como snapshot sem risco de
desatualizar. O api#109 acrescentou edição e remoção a EquipmentModels — porque catálogo global sem
manutenção acumula erro de digitação e dado de teste pra sempre — e com isso duas coisas passaram a
valer:

- **Renomear uma entrada corrige os equipamentos que a usam**: quem faz isso é o `EquipmentProjector`
  (do módulo Equipments) reagindo a `EquipmentModelUpdated`, porque Equipments pode conhecer
  EquipmentModels e não o contrário. É projector e não reactor de propósito: um replay precisa
  reaplicar o rename.
- **A listagem de equipamentos passa a ser invalidada por evento de catálogo** — ver § Cache.

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
  - **Exceção em vigor: `EquipmentPhotoReactor`** (api#102, primeiro Reactor do repositório), que
    apaga o arquivo da foto e roda **síncrono**. Das duas razões acima, a que importa aqui é a
    segunda — e ela vale por ser Reactor, com ou sem fila: replay não executa Reactor. Já a
    primeira não compensa hoje: **não roda worker de fila em produção** (ver `ambientes.md`), então
    um job enfileirado ficaria parado pra sempre e o arquivo nunca seria apagado. Apagar um arquivo
    é rápido; segurar a resposta com isso é melhor do que vazar arquivo órfão no Object Storage.
    Quando a fila passar a rodar de verdade, mover pra `ShouldQueue`.
  - Regra geral que isso ilustra: **efeito colateral fora do banco nunca vai no Projector.** O
    Projector reexecuta a cada `event-sourcing:replay`; se ele mexesse em disco, restaurar o banco
    de um backup e reconstruir as projeções apagaria arquivos que deveriam continuar lá — e o
    binário não está no event store pra ser recuperado.
- O auto-discovery do spatie (`config/event-sourcing.php`) assume a convenção padrão do Laravel
  (namespace `App\` = pasta `app/`), que não bate com `Modules\<Módulo>\` = `Modules/<Módulo>/app/`
  de cada módulo. Em vez de reconfigurar o scanner por módulo, cada `<Módulo>ServiceProvider::boot()`
  registra explicitamente o seu: `Projectionist::addProjector(FooProjector::class)`.
- `php artisan event-sourcing:replay` reconstrói todos os read models a partir de
  `stored_events` — útil depois de corrigir um bug de projeção ou adicionar uma coluna nova.

## Cache — listagens em Valkey, invalidadas por contador de versão

Endpoints de listagem (`index`) cacheiam a resposta lida (`Cache::remember(...)`) — `GET
/clients`, `GET /clients/{id}/equipments`, `GET /orders`. `show`/detalhe fica de fora por
enquanto.

**Os catálogos globais** (`GET /accessories`, `GET /equipment-models`) não são cacheados: tabelas
pequenas, sem filtro. Mas desde o api#109 eles **invalidam a listagem de equipamentos**, porque ela
embute o nome/marca/modelo copiado do catálogo e o nome do acessório vindo pela relação — sem isso,
corrigir um nome não apareceria por até uma hora.

Quem incrementa `equipments:cache-version` é o **`EquipmentProjector`**, reagindo aos eventos de
catálogo, e não o projector de cada catálogo: a chave pertence a Equipments, e os módulos de
catálogo estão abaixo dele no grafo — não podem conhecê-lo. (Este parágrafo já anunciou o contrário
enquanto os catálogos eram append-only; a versão anterior previa por escrito que isso mudaria.)

Servidor real por trás do cache: **Valkey** (fork open-source do Redis mantido pela Linux
Foundation, mesmo protocolo RESP e mesmos comandos — criado depois da Redis Inc. mudar a licença
do Redis pra uma não-OSI em 2024). O Laravel não tem um driver "valkey" separado: `CACHE_STORE`
continua `redis` e as variáveis de ambiente continuam `REDIS_*` — é a nomenclatura do driver do
framework (amarrada ao protocolo, não ao software), não o nome do que roda de fato. O que muda
de nome é só a infraestrutura: o serviço `valkey` (antes `redis`) no `docker-compose.yml`.

- **Contador de versão por módulo** (`Cache::get('equipments:cache-version', 0)`), embutido na
  chave de cache (`"equipments:index:v{$version}:{$id}"`) — não `Cache::tags()`. **Trocado em
  14/09/2026** depois de um bug em produção: acessório salvo "sumia" ao reabrir o equipamento.
  `Cache::tags()->flush()` usa operações multi-chave no Redis (um conjunto por tag, manipulado
  com `SADD`/`SPOP`) — fonte conhecida de comportamento inconsistente em topologias Redis/Valkey
  geridas com réplica ou cluster (comuns em provedores gerenciados), que o Valkey local de
  desenvolvimento (um container só) nunca reproduziria. `Cache::increment()` — usado pra
  invalidar — é uma única chave (`INCRBY`), funciona igual em qualquer topologia: zero operação
  multi-chave, zero essa classe de risco.
  - **O padrão de leitura é `0`, não `1`** — achado num segundo bug (15/09/2026), reproduzido
    localmente: o primeiro `Cache::increment()` de verdade também produz `1` (Redis trata
    `INCRBY` numa chave inexistente como se partisse de 0). Se o padrão de leitura fosse `1`,
    uma leitura feita **antes** de qualquer escrita cairia na mesma chave versionada que a
    leitura de **depois** da primeira escrita da vida daquele módulo — a lista vazia cacheada
    na primeira leitura sobrevivia ao cadastro seguinte. `0` nunca é um valor real pós-
    incremento (que começa em `1`), então as duas leituras nunca mais colidem, em nenhuma
    ordem entre leitura e escrita.
  - Uma versão por módulo inteiro (`'clients'`, `'equipments'`, `'orders'`), não por
    cliente/recurso individual — uma escrita em qualquer registro do módulo invalida a listagem
    inteira, mesmo de um cliente não afetado. Granularidade por cliente foi cogitada e
    descartada: alguns eventos (ex.: `EquipmentUpdated`/`EquipmentRemoved`) nem carregam o
    `client_id`, e listas por cliente são pequenas o bastante pra um cache miss a mais em
    clientes não afetados não pesar.
- **Chave de cache**: a URL completa da requisição (`sha1($request->fullUrl())`) quando o
  endpoint tem filtros/paginação (Clients, Orders) — cobre todos os parâmetros de uma vez, sem
  listar cada um manualmente. Equipments (sem filtro, só `client_id` na rota) usa só o id. A
  versão do módulo sempre entra como parte da chave, antes do resto.
- **Invalidação orientada a evento, não TTL**: cada `Projector` do módulo chama
  `Cache::increment('<módulo>:cache-version')` ao final de todo `on*` que ele já implementa — o
  Projector já é o único lugar que escreve no read model, então vira também o único lugar que
  invalida o cache dele. As chaves da versão antiga não são apagadas explicitamente — ficam
  órfãs (nunca mais montadas por nenhuma leitura, já que a versão mudou) e somem sozinhas pelo
  TTL. O `now()->addHour()` passado a `remember()` é só um limite de segurança, não a estratégia
  real de expiração.
- **Sem helper/trait compartilhado entre módulos**: é a mesma meia-dúzia de linhas repetida por
  módulo — `Cache` é uma facade do framework, não um import de módulo, então usá-la direto em
  cada um não fere a regra de fronteira acima, e este projeto não tem um "Modules/Shared" nem um
  `app/` raiz onde colocar algo assim sem inventar um lugar novo só pra isso.
- `phpunit.xml` roda a suíte com `CACHE_STORE=array` — os testes não dependem de um Valkey de
  verdade (o store `array` guarda tudo em memória do próprio processo, `increment()`/`get()`
  funcionam igual em qualquer store).

## Por que Event Sourcing (e não só "publicar eventos")

Duas coisas em uma: (1) `stored_events` é a fonte da verdade auditável de tudo que aconteceu com
uma OS (quem mudou o quê e quando — natural para uma ferramenta que substitui um controle manual
em Excel), e (2) os mesmos eventos que constroem a projeção são o que um módulo publica para
outro reagir, sem precisar inventar um "barramento de eventos" ou fila de integração separada —
o pacote já entrega os dois com a mesma peça (`ShouldBeStored` + `Projector`/`Reactor`).

## Monitoramento — métricas por OpenTelemetry, empurradas pro Grafana Cloud

Desde 18/09/2026, a observabilidade deste backend é feita por métricas instrumentadas pelo
[`keepsuit/laravel-opentelemetry`](https://github.com/keepsuit/laravel-opentelemetry) e enviadas
por OTLP/HTTP direto pro Grafana Cloud. Substituiu o Laravel Pulse (12/09–18/09/2026), que era um
dashboard server-rendered hospedado dentro da própria aplicação — junto com ele saíram o único
Blade deste repo (`resources/views/vendor/pulse/dashboard.blade.php`) e o único gate fora de
`Modules/` (`Gate::define('viewPulse', ...)`, que morava em
`Modules/Identity/app/Providers/IdentityServiceProvider.php` por falta de um `app/Providers` neste
projeto). A exceção documentada aqui deixou de existir, em vez de trocar de dono.

**Duas métricas, de propósito** — as mesmas duas coisas que os cards do Pulse realmente respondiam:

- `http.server.request.duration` (histograma, em segundos) — duração, contagem e taxa de erro das
  requisições, tudo derivável do histograma mais o label de status.
- `db.client.operation.duration` (histograma, em segundos) — o equivalente ao "slow queries" do
  Pulse, com uma diferença importante: não é um log pré-filtrado por um limiar fixo, é a
  distribuição inteira. O limiar de "lenta" vira um percentil num painel/alerta do Grafana, e pode
  mudar sem redeploy.

`http.client.request.duration` e as métricas do Redis vêm de brinde com o pacote — não construímos
nada em cima delas. Sem tracing distribuído, sem pipeline de logs, sem métrica de negócio
instrumentada: `traces.exporter` e `logs.exporter` estão fixados em `'null'` no
`config/opentelemetry.php` (hardcoded, não por env — `OTEL_TRACES_EXPORTER=null` no `.env` viraria
`null` de PHP, não a string, e o default do pacote é `otlp`). `tests/Feature/ObservabilityConfigTest.php`
trava essas duas decisões.

**Por que empurrar, e não expor um endpoint de scrape.** A mesma restrição que já tinha derrubado o
card "Servers" do Pulse: o compute da Laravel Cloud é efêmero, gerenciado pela plataforma, não
compartilhado entre réplicas e sem história de sidecar/daemon — e este projeto não roda worker de
fila em produção. Um Prometheus fazendo scrape precisaria de um alvo estável e alcançável de fora;
um `pulse:check` precisaria de um daemon. Um POST OTLP de saída, feito pelo próprio processo que
atendeu a request, não precisa de nenhum dos dois. Foi o que decidiu entre OpenTelemetry e
`promphp/prometheus_client_php`.

**O preço de instrumentar PHP stateless**, que vale entender antes de mexer na configuração:

- Cada request é um processo novo. O pacote, sem `OTEL_SERVICE_INSTANCE_ID`, gera um
  `service.instance.id` **aleatório por request** — e o Grafana Cloud mapeia esse atributo pro
  label `instance`, ou seja: uma série de métrica nova a cada requisição. `config/opentelemetry.php`
  cai em `gethostname()` justamente para dar um id estável por container.
- Pelo mesmo motivo, a temporalidade teoricamente correta seria **Delta** (Cumulative reiniciada a
  cada request faz o `rate()` subcontar) — mas o gateway do Grafana Cloud recusou Delta com "Bad
  Request" no teste de 17/09/2026. Ficamos com **Cumulative** mesmo, sabendo que a contagem vira um
  piso, não um número exato.
- O POST OTLP acontece no `terminate()` da request. Em produção (PHP-FPM) a resposta já foi
  entregue ao cliente antes disso; no Sail local (`php artisan serve`) não há esse mecanismo, então
  ligar o SDK localmente acrescenta o round-trip até o Grafana na latência de cada request.

Uma armadilha à parte, de framework, não de infra: **`OTEL_SDK_DISABLED` nunca pode ser setado via
`<env>` do PHPUnit** — ver `CLAUDE.md` § OpenTelemetry pra causa raiz (resumo: o PHPUnit converte o
literal `"true"` em bool nativo do PHP, que vira `"1"` ao reexportar pro processo, e o parser do
SDK só aceita a string `"true"`/`"false"`). A variável é setada via `putenv()` puro em
`tests/bootstrap.php`, referenciado por `phpunit.xml`.

Credenciais, variáveis de ambiente e como ler os painéis: `docs/ambientes.md` § Monitoramento.

## Comandos úteis do nwidart/laravel-modules

- `php artisan module:make <Nome> --api` — cria um módulo novo (só a parte de API; sem Blade).
- `php artisan module:list` — módulos e status (habilitado/desabilitado, `modules_statuses.json`).
- `php artisan module:make-migration <nome> <Módulo>` — nova migration dentro do módulo certo.
