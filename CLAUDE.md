# Instruções para o Claude neste repositório

Lições de erros já cometidos nesta base de código — leia antes de trabalhar em `Modules/` ou em
`tests/`. As convenções de arquitetura/negócio ficam em `docs/` (`architecture.md`,
`api-conventions.md`, `openapi.yaml`, `ambientes.md`); este arquivo é só sobre armadilhas
operacionais do ambiente e do framework.

## Git neste checkout: `Modules/` pode estar em minúsculas no disco

Em ambiente Windows/NTFS (case-insensitive, case-preserving), os diretórios dentro de `Modules/`
às vezes existem fisicamente em minúsculas (`Modules/orders`, `Modules/clients`...) mesmo com o
Git rastreando os caminhos certos em PascalCase (`Modules/Orders/...`). Dois efeitos práticos:

- **Nunca use `git add -A`, `git add .` ou `git add Modules/` (glob) para arquivos novos dentro de
  `Modules/`.** Um glob/diretório resolve pela casing real do disco (minúscula) e o Git registra o
  arquivo novo com esse path errado — diverge do padrão do resto do repositório e pode quebrar
  autoload num filesystem case-sensitive de verdade (produção, CI). **Sempre `git add` cada
  arquivo novo pelo caminho exato e correto**, ex.: `git add "Modules/Orders/app/Application/Foo.php"`
  (funciona mesmo em disco minúsculo — o Git preserva a casing que você digitou). Depois de um
  `git add`, confira com `git status --porcelain`: se aparecer em minúsculo, foi adicionado errado.
- `tests/Architecture/ModuleBoundariesTest.php` compara o nome do diretório do módulo
  (`basename($modulePath)`) com o segmento do namespace extraído de cada `use Modules\X\...`. Essa
  comparação já foi corrigida para ignorar caixa (`strcasecmp`) — se voltar a usar `===`, o teste
  falsamente acusa **todo módulo de importar a si mesmo** neste ambiente. Não reverta esse trecho
  sem entender por quê ele existe.

## Testes HTTP com token real: cuidado com guard memoizado

`Illuminate\Auth\RequestGuard::user()` guarda o usuário resolvido **para sempre** na instância do
guard, e `Illuminate\Auth\AuthManager` cacheia a instância do guard pelo resto do processo — que,
nos testes, é o resto do **método de teste** (múltiplas chamadas `getJson()`/`postJson()` dentro
de um mesmo `test_*` reaproveitam o mesmo container).

- **`actingAs($user, 'sanctum')` não sofre disso** — ele sobrescreve o usuário do guard
  diretamente a cada chamada, sempre correto. É o padrão usado em quase todos os testes deste
  repositório.
- **Autenticação via header real (`withHeader('Authorization', "Bearer {$token}")`) sofre disso.**
  Se você fizer uma chamada autenticada por header, depois OUTRA chamada autenticada por header
  (mesmo com token diferente, revogado, ou de outro usuário) **no mesmo método de teste**, a
  segunda chamada silenciosamente reutiliza o usuário resolvido na primeira — não importa o que
  você mandou no header. Isso não é um bug da aplicação (em produção cada request é um processo
  novo, sem esse cache entre chamadas) — é uma particularidade só deste harness.
- **Como testar revogação/expiração de token corretamente**: faça UMA chamada HTTP por método de
  teste, e verifique o efeito no banco diretamente (`assertDatabaseMissing`/`assertDatabaseHas` em
  `personal_access_tokens`), em vez de encadear uma segunda chamada HTTP esperando 401. Ver
  `tests/Feature/Modules/IdentityHttpTest.php::test_logout_revokes_only_the_current_token` para o
  padrão certo.

## Helpers de teste com e-mail fixo: um por método de teste

`authenticatedUser()` (em `ClientsHttpTest`, `EquipmentsHttpTest`) e `aUser()` (em
`IdentityHttpTest`) sempre registram o mesmo e-mail fixo (`ana@medfusion.example`). Chamar o
helper **mais de uma vez no mesmo método de teste** (ou registrar manualmente outro usuário com
esse e-mail) estoura a constraint `unique` de `users.email` com `UniqueConstraintViolationException`
— guarde o retorno numa variável e reutilize:

```php
$user = $this->authenticatedUser();
$this->actingAs($user, 'sanctum')->getJson(...);
$this->actingAs($user, 'sanctum')->getJson(...); // mesmo $user, sem registrar de novo
```

## Grafo de dependências entre módulos, confirmado por auditoria

A regra de `docs/architecture.md` ("Domain e Application nunca cruzam módulo") é abstrata —
este é o grafo real de quem lê quem, confirmado em 13/09/2026 apagando cada módulo de verdade
(branch descartável + `git rm -r Modules/<Nome>` + `php artisan test`, revertido depois) e
vendo o que quebrava:

```
Identity    ← lido por: todo mundo (auth:sanctum) e por Orders (Order::user())
Clients     ← lido por: Equipments (Client::findOrFail no controller) e Orders
              (Order::where('client_id', ...), Client::orders() já foi removida por não ter uso)
Accessories ← lido por: Equipments (RegisterAccessory chamado da Presentation pra cadastrar um
              acessório novo digitado na hora, ver EquipmentController::resolveAccessories; FK de
              verdade no banco em equipment_accessories.accessory_id, api#92)
Equipments  ← lido por: Orders (OrderEquipment, e uma FK de verdade no banco)
Orders      ← não é lido por nenhum outro módulo — fica no topo da pilha
```

`Clients`, `Identity`, `Accessories` e `EquipmentModels` são a base do grafo. **No `Domain` e no
`Application` deles, nunca importe nada de um módulo "de cima"** (`Equipments`/`Orders`) — é o que o
`ModuleBoundariesTest` trava, e se você precisar disso, pare: é sinal de que a dependência foi
modelada ao contrário.

**Na `Presentation`, compor leitura para cima é permitido e já é praticado** — não confunda as duas
coisas (esta frase já esteve absoluta aqui e contradizia o próprio código). `ClientController`, de
um módulo base, importa `Modules\Orders\...\Order`, `Modules\Equipments\...\Equipment` e
`RemoveEquipment`: é assim que ele devolve 409 quando o cliente tem OS vinculada. `EquipmentModels`
faz o mesmo no api#109 pra recusar a remoção de um modelo em uso. A regra real: **a decisão de
negócio composta entre módulos mora na Presentation, nunca na Action.**

Três coisas que a auditoria descobriu e valem a pena lembrar ao mexer nesse grafo (ex.: ao
construir o CRUD de OS, api#45/#46, que vai fazer Orders crescer bastante):

- **Não crie uma relação Eloquent cross-module "por completude"**, sem um consumidor real na
  hora. `Client::equipments()`/`Client::orders()` existiam assim, nunca foram chamadas por
  nenhum controller/resource/teste, e apontavam pra direção errada do grafo acima — removidas
  nesta auditoria. Uma relação sem uso ainda conta como acoplamento (import, superfície), só
  que sem nenhum benefício em troca.
- **Uma FK nova numa migration que aponta pra tabela de outro módulo é acoplamento real**,
  mesmo sem nenhum `use Modules\X` em PHP nenhum — `ModuleBoundariesTest` (e qualquer grep)
  não enxerga isso. `order_equipments.equipment_id` (FK pra `equipments`) só apareceu na
  auditoria quando apagar o módulo Equipments quebrou a inserção com "no such table" — nenhuma
  leitura de código a revelaria antes disso. Ao adicionar uma FK assim, deixe registrado
  (comentário na migration já é o padrão deste repo) que é uma dependência cross-module.
- **Injetar uma Action de outro módulo num método de Presentation** (ex.:
  `ClientController::destroy(string $id, RemoveClient $removeClient, RemoveEquipment $removeEquipment)`)
  faz o Laravel resolver todo parâmetro por reflection antes do corpo do método rodar — se
  `RemoveEquipment` não existisse, o método inteiro falharia com 500, mesmo num teste que nunca
  chegaria perto de remover equipamento (ex.: o 409 de OS vinculada, que retorna antes). Não é
  um bug pra corrigir — é como injeção de dependência funciona — mas influencia o quão
  "opcional" essa composição de fato é: uma vez injetada, o módulo de origem passa a depender
  do de destino sempre existir, não só quando aquele código específico roda.

Pra reconferir esse grafo depois de uma mudança grande (não só confiar que continua certo):
branch descartável, `git rm -r Modules/<Nome>`, `php artisan test`, registrar o que quebrou,
`git checkout main && git branch -D <branch>`. Nunca faça isso numa branch com trabalho de
verdade nem dê push nela.

## Cache (driver `redis`, servidor Valkey): nunca guarde stdClass — e o `array` driver dos testes não pega esse bug

`config/cache.php` traz `'serializable_classes' => false` por padrão (proteção do próprio
Laravel contra injeção de objeto via `unserialize()` de dado vindo do cache). Isso faz o
`RedisStore::unserialize()` rodar com `unserialize($value, ['allowed_classes' => false])` —
**bloqueia TODO objeto**, inclusive `stdClass`: qualquer objeto guardado no cache volta como
`__PHP_Incomplete_Class` (aparece no JSON como uma chave literal `"__PHP_Incomplete_Class_Name"`
espalhada pela resposta). Descoberto ao cachear `SomeResource::collection(...)->response()->getData()`
(que devolve `stdClass`) — a correção é `getData(true)` (array, não objeto; array não sofre essa
restrição). Doeu para descobrir porque:

- **`phpunit.xml` roda a suíte com `CACHE_STORE=array`, e o `array` driver nunca serializa nada**
  (fica em memória do próprio processo) — então esse bug de serialização específico do driver
  `redis` (RESP, seja o servidor Redis ou Valkey) é **invisível pros testes automatizados**, não
  importa quantas asserções de "cache hit" você escrever. Só aparece testando de verdade contra
  o Valkey do `docker compose` (curl real, ou `docker compose exec valkey valkey-cli -n 1 keys
  '*'` pra ver as chaves cruas — o nome do serviço é `valkey`, não `redis`, desde que trocamos o
  servidor).
- Sempre que cachear o retorno de um endpoint (`Cache::remember`), garanta que o valor é
  array/scalar, nunca um objeto (`Resource`, `stdClass`, Eloquent model, `Collection`) — em
  QUALQUER nível de aninhamento, não só no topo.
- **`->toArray($request)` sozinho não é garantia — só resolve o nível de fora.** Achado num
  segundo caso (15/09/2026, `EquipmentController::index`): o `toArray()` de
  `EquipmentResource` fazia `$this->accessories->map(fn ($item) => [...])` — `->map()` numa
  `Collection` devolve OUTRA `Collection`, não um array puro, e isso ficava aninhado dentro do
  array que o Resource devolve. `ResourceCollection::toArray()` resolve a lista de recursos em
  array, mas não desce recursivamente dentro do `toArray()` de cada um pra converter objetos
  aninhados. Resultado: o objeto sobrevivia escondido um nível abaixo, guardado assim no cache,
  e voltava quebrado (ver bug acima) sem nenhum erro na hora de salvar — só ao reler depois.
  **Prefira sempre `->response()->getData(true)`** (um round-trip de verdade por JSON, que
  resolve tudo recursivamente pra array puro, Resources/Collections aninhados incluídos) em vez
  de confiar em `->toArray()` puro quando o Resource tem qualquer campo composto.

## Acrescentar campo a um evento já gravado: precisa de default OU de nulabilidade

Ao adicionar um parâmetro novo a uma classe `ShouldBeStored` que já tem eventos no banco
(`stored_events`), o payload antigo não tem essa chave. O construtor precisa conseguir produzir um
valor mesmo assim — e para isso **um default OU o tipo ser nullable já basta**; o que quebra é não
ter nenhum dos dois. Matriz confirmada empiricamente em 15/09/2026 no api#101, rodando o teste de
replay com cada variação:

| Assinatura | Replay de evento antigo |
|---|---|
| `?string $x = null` | funciona |
| `?string $x` (nullable, sem default) | funciona |
| `string $x = 'algo'` (não-nullable, com default) | desserializa (usa o default) |
| `string $x` (não-nullable, sem default) | quebra com `InvalidStoredEvent` |

Ou seja: não basta olhar só pro default nem só pro `?`. Na prática, **prefira `?tipo $x = null`**
nos dois papéis ao mesmo tempo — e entenda que `null` normalmente significa "desconhecido naquele
evento", não "vazio de propósito"; quem decide o que fazer com isso é o projector (no api#101 ele
resolve o modelo pelo trio nome/marca/modelo).

Um cuidado à parte do default: um valor que desserializa não é necessariamente um valor **válido**.
No teste acima, `string $x = 'algo'` passou pela desserialização e só estourou depois, no `INSERT`,
por não ser um uuid existente na FK. Se o campo novo é uma chave estrangeira, `null` é o único
default honesto.

Coloque o parâmetro novo **por último**, porque as chamadas existentes são posicionais.

### E **mudar o tipo** de um campo que já existe é quebra, não ajuste

Pior que acrescentar campo, e foi como quebramos a produção em 15/09/2026 (api#108): o api#98 trocou
`public readonly ?string $accessories` por `public readonly array $accessories` em
`EquipmentRegistered`/`EquipmentUpdated`, quando acessórios deixaram de ser texto livre. Os eventos
gravados antes seguem no banco com uma string ali, e o construtor novo os rejeita com
`InvalidStoredEvent` / `NotNormalizableValueException`.

A regra, que vale pra sempre: **uma classe de evento precisa conseguir desserializar todo payload
que já foi escrito pra ela.** Se o formato mudar, o construtor aceita os dois (`string|array|null` e
normaliza no corpo, foi o que fizemos) — nunca só o novo.

O que tornou isso invisível por dois dias, e é o que vale lembrar ao testar uma mudança dessas: **o
sintoma não aparece em leitura.** A listagem lê a projeção e continua perfeita; só `retrieve()`
relê o stream, e ele só roda no **PUT**, no **DELETE** e no `event-sourcing:replay`. Depois de mexer
em classe de evento, teste editar e excluir um registro **antigo**, não só cadastrar um novo — e
lembre que um `event-sourcing:replay` quebrado significa ficar sem o plano B de reconstruir
projeção.

Dois detalhes do pacote que economizam tempo ao escrever um teste de replay:

- O uuid do agregado **não** vem da coluna `aggregate_uuid`: `ShouldBeStored::aggregateRootUuid()`
  lê `metaData['aggregate-root-uuid']` (ver `Spatie\EventSourcing\Enums\MetaData`). Inserir um
  `stored_events` na mão com `meta_data` vazio faz o projector receber `null` e estourar
  `TypeError`.
- Reprojetar no teste: `Projectionist::replay(collect([app(SeuProjector::class)]))`.

## "Fecha #N" em português NÃO fecha a issue

O corpo das PRs aqui é escrito em português, e escrever "Fecha #101" **não fecha nada**: o GitHub só
reconhece palavra-chave de fechamento em inglês (`close`/`closes`/`closed`, `fix`/`fixes`/`fixed`,
`resolve`/`resolves`/`resolved`). Em português ele trata como texto comum e a issue fica aberta
para sempre, mesmo com a PR mergeada.

Isso já aconteceu com #92, #101 e #102 (e com web#87, #92, #93) — todas entregues e esquecidas
abertas, dando a impressão de backlog pendente que não existe.

Duas saídas, escolha uma e seja consistente: escrever `Closes #101` no corpo da PR (mistura idioma,
mas fecha sozinho), ou manter o português e **fechar a issue à mão depois do merge**, com um
comentário dizendo qual PR entregou. O que não vale é escrever "Fecha #N" e achar que resolveu.

## Recusar uma remoção: cheque ANTES, nunca pelo erro do banco

Tentador: chamar a Action de remoção e traduzir a violação de chave estrangeira em 409. **Não
funciona**, e o motivo é do Event Sourcing, não do banco — verificado na marra no api#109:
`persist()` grava o evento em `stored_events` e **só então** roda o projector, onde a FK estoura. O
resultado de uma remoção "recusada" assim é um `...Removed` gravado com a linha ainda existindo: o
agregado passa a se achar removido, e um replay apaga um registro que a produção tem.

Cheque antes de chamar a Action (`Equipment::where(...)->exists()` na Presentation) e devolva 409
ali. A FK `restrictOnDelete` continua valendo como rede de segurança pra corrida entre requisições.

O teste que trava isso não é o status 409 — é
`assertDatabaseMissing('stored_events', ['event_class' => ...Removed::class])` depois da recusa.

## OpenTelemetry: `OTEL_SDK_DISABLED` não pode ser um `<env>` do PHPUnit

Achado ao instalar `keepsuit/laravel-opentelemetry` (16/09/2026): com
`<env name="OTEL_SDK_DISABLED" value="true"/>` no `phpunit.xml`, a suíte inteira ficava várias
vezes mais lenta (cada teste levando segundos) e o log enchia de
`OpenTelemetry: [warning] Invalid boolean value "1" ...`.

Causa: o PHPUnit converte os literais `"true"`/`"false"` de `<env>` em bool nativo do PHP antes de
reexportar pro processo via `putenv()`, e `(string) true` é `"1"`. O parser de boolean do SDK OTel
só aceita as strings `"true"`/`"false"` (case-insensitive) — ao ver `"1"` ele loga o aviso e trata
como `false`, ou seja, como se o SDK estivesse **ligado**. O `keepsuit/laravel-opentelemetry`
tenta corrigir isso sincronizando o valor de volta via `Env::getRepository()->set(...)` no boot,
mas isso só atualiza o repositório interno do Dotenv (o que `env()`/`config()` do Laravel leem) —
não o `getenv()` bruto do processo, que é o que `Sdk::isDisabled()` de fato consulta. Com o SDK
"achando" que está ligado, ele registra instrumentação de verdade (HTTP, queries, cache, console)
em cima de toda a suíte.

Confirmado com um teste temporário chamando `\OpenTelemetry\SDK\Sdk::isDisabled()` diretamente:
retornava `false` mesmo com `config('opentelemetry.disabled')` corretamente `true`.

Correção: setar essa variável específica via `putenv()` puro num bootstrap customizado
(`tests/bootstrap.php`), **antes** de qualquer autoload, e apontar `phpunit.xml` pra ele
(`bootstrap="tests/bootstrap.php"`) em vez do `vendor/autoload.php` direto — nunca via `<env>` pra
essa variável. `config/opentelemetry.php` também trava `traces.exporter`/`logs.exporter` em
`'null'` hardcoded (não por env, pelo mesmo motivo: `OTEL_TRACES_EXPORTER=null` no `.env` vira
`null` de PHP no `env()` do Laravel, não a string `'null'`).

Se o teste de performance parecer "consertado" só porque as mensagens de warning sumiram, não
confie — confirme rodando `\OpenTelemetry\SDK\Sdk::isDisabled()` de verdade, porque
`config('opentelemetry.disabled')` (o valor do NOSSO lado) pode estar certo enquanto o do SDK
continua errado.

## Antes de assumir o estado de uma PR/issue

Não confie em contexto de sessão anterior (resumo de conversa, plano salvo) para saber se uma PR
já foi mergeada ou uma issue já foi fechada — o usuário pode ter aprovado/mergeado pelo GitHub
entre uma sessão e outra. Confira com `gh pr view <n> --json state,mergeable` /
`gh issue view <n> --json state` antes de continuar um branch ou reabrir trabalho.
