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
Identity   ← lido por: todo mundo (auth:sanctum) e por Orders (Order::user())
Clients    ← lido por: Equipments (Client::findOrFail no controller) e Orders
             (Order::where('client_id', ...), Client::orders() já foi removida por não ter uso)
Equipments ← lido por: Orders (OrderEquipment, e uma FK de verdade no banco)
Orders     ← não é lido por nenhum outro módulo — fica no topo da pilha
```

`Clients` e `Identity` nunca devem importar nada de `Equipments`/`Orders`/um do outro — são a
base do grafo. Se um dia um desses dois precisar importar algo de um módulo "de cima", pare e
reconsidere — é sinal de que a dependência foi modelada ao contrário.

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

## Antes de assumir o estado de uma PR/issue

Não confie em contexto de sessão anterior (resumo de conversa, plano salvo) para saber se uma PR
já foi mergeada ou uma issue já foi fechada — o usuário pode ter aprovado/mergeado pelo GitHub
entre uma sessão e outra. Confira com `gh pr view <n> --json state,mergeable` /
`gh issue view <n> --json state` antes de continuar um branch ou reabrir trabalho.
