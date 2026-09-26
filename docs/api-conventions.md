# Convenções da API

> Complementa [`openapi.yaml`](./openapi.yaml) (issue [#21](https://github.com/celsojuniorsfs/medfusion-os-api/issues/21)).
> Insumo direto das issues [#28](https://github.com/celsojuniorsfs/medfusion-os-api/issues/28)
> (API Resources e formato de erro) e [#29](https://github.com/celsojuniorsfs/medfusion-os-api/issues/29)
> (estratégia Sanctum).
>
> **Atualizado em 08/09/2026**: nomes de campo padronizados em inglês (ex.: `razao_social` →
> `company_name`, `numero` → `number`, status `aberta` → `open`). O significado de negócio não
> muda — só o nome técnico do campo. Tabela completa de correspondência no fim deste documento.
>
> **Atualizado em 08/09/2026 (arquitetura)**: `id` passou de inteiro sequencial para UUID —
> identidade dos agregados do Event Sourcing. Ver [`architecture.md`](./architecture.md) para a
> arquitetura completa (monólito modular, DDD-like, `spatie/laravel-event-sourcing`). `number` da
> OS **não muda**: é o número de negócio (seed 1336, editável pelo técnico), sem relação com a
> identidade do agregado.
>
> **Atualizado em 10/09/2026**: cadastro de cliente passa a cobrir pessoa física explicitamente,
> não só jurídica (feedback do Augusto — parte dos clientes cadastra em nome próprio, com CPF).
> `clients.company_name` vira `name`; novos campos `person_type` (`individual`/`company`),
> `trade_name`, `state_registration`, `email` e `state` (UF). `tax_id` e `postal_code` passam a
> ser gravados só com dígitos (antes ficavam com pontuação) — necessário pra constraint `unique`
> de `tax_id` funcionar de verdade e pros tamanhos de coluna fazerem sentido; a máscara na
> exibição é responsabilidade do frontend.

## Versionamento

Prefixo de URL: todas as rotas ficam sob `/api/v1`. Sem negociação por header — mais simples de
testar (`curl`, Postman) e de documentar. Uma v2 futura, se necessária, ganha seu próprio prefixo
e roda em paralelo enquanto o frontend migra.

## Autenticação — Sanctum em modo token

Frontend (Vercel) e API (Laravel Cloud) vivem em domínios diferentes, então usamos Sanctum no modo
**personal access token** (Bearer), não no modo cookie/SPA:

- `POST /auth/login` valida e-mail/senha e retorna um token via
  `$user->createToken('web')->plainTextToken`.
- O Angular guarda o token (ver web #32) e o envia em `Authorization: Bearer <token>` em toda
  chamada — sem cookies, sem CSRF, sem `SANCTUM_STATEFUL_DOMAINS`.
- `POST /auth/logout` revoga apenas o token corrente
  (`$request->user()->currentAccessToken()->delete()`), não todos os tokens do usuário.
- Token expira em 30 dias (`config/sanctum.php`, `SANCTUM_TOKEN_EXPIRATION_MINUTES`) — revisto no
  code review de 13/09/2026: a decisão original da F3 era `expiration` ficar `null` (nunca
  expira), mas sem fluxo de refresh token isso deixava um token vazado/perdido válido pra sempre.
  30 dias é longo o bastante pra não incomodar um técnico em campo, mas limita a janela de um
  token comprometido. Múltiplos papéis de usuário continua no backlog pós-v1.
- `POST /auth/login` tem rate limit de 5 tentativas/minuto por e-mail+IP (limiter `login`,
  registrado em `IdentityServiceProvider::boot()`) — achado do mesmo review: não havia nenhum.
- Rotas protegidas usam o middleware `auth:sanctum` (ver api #33).
- Não há registro de usuário pela API — usuários são criados via seed/tinker (api #34), condizente
  com "poucos técnicos" definido no escopo.

## API Resources e formato de resposta

- Toda resposta de recurso único usa um `JsonResource` e é envelopada em `{ "data": ... }`.
- Toda listagem paginada usa `AnonymousResourceCollection::paginate()` (paginação padrão do
  Laravel), que já produz o envelope `{ data, links, meta }` documentado no `Pagination` schema
  do OpenAPI.
- Relações (`client`, `user`, `equipments`, `items` em `Order`) são sempre carregadas via
  `with()` no controller — nunca lazy-loaded na resposta, para evitar N+1.

## Formato de erro

- **Validação (`422`)**: formato padrão do `FormRequest` do Laravel —
  `{ "message": "The given data was invalid.", "errors": { "campo": ["mensagem"] } }`.
  Não é customizado; é o que o `ValidationException` do framework já produz.
- **Conflito (`409`)**: reservado para número de OS duplicado. Lançado explicitamente no
  `OrderService` (api #44) como uma exceção própria (`DuplicateOrderNumberException`), que define
  o próprio `render()` e devolve `{ "message": "..." }` com status 409 — não existe
  `app/Exceptions/Handler.php` neste projeto (sem `app/`, ver `architecture.md`), então não há
  "Handler" pra capturar nada; corrigido no code review de 13/09/2026 (texto desatualizado desde
  a implementação do api #44, que já tinha ido pelo caminho do `render()` próprio).
- **Demais erros** (`401`, `403`, `404`, `500`): `{ "message": "..." }`, usando o tratamento
  padrão de exceções do Laravel — nenhuma customização necessária além de garantir que
  `APP_DEBUG=false` em produção não vaze stack trace (ver `ambientes.md`).

## Concorrência na numeração da OS

Duas requisições simultâneas de criação não podem obter o mesmo número. Estratégia (detalhada na
issue api #23, F2):

1. Constraint `unique` em `orders.number` no banco — a garantia real está aqui, não na aplicação.
2. `GET /orders/next-number` é apenas uma **sugestão de UI**; o valor não é reservado.
3. Ao salvar (criação ou edição), a transação roda com retry (`DB::transaction($callback,
   attempts: 3)`) — se a segunda requisição esbarra num lock ainda aberto pela primeira em vez de
   já achar a constraint violada, o retry embutido do Laravel (via
   `Illuminate\Database\ConcurrencyErrorDetector`) tenta de novo em vez de vazar essa contenção
   transitória como erro. Na nova tentativa, ou o número já está livre (a outra transação
   desistiu) ou a constraint `unique` dispara de verdade — a aplicação captura essa exceção de
   integridade e responde `409` em vez de vazar um erro 500 de SQL.

## Status da OS — transições

Fluxo fechado nas duas rodadas de validação de escopo com o cliente (ver `escopo-v1.md` §
Status da OS e `CONTEXT.md` § Validação com o cliente). Valores traduzidos para inglês em
08/09/2026 — a correspondência com os termos usados na conversa com o cliente está entre
parênteses.

| De | Para | Quando |
|---|---|---|
| `open` (aberta) | `in_analysis` (em análise) | diagnóstico começou |
| `in_analysis` | `external_quote` (orçamento externo) | enviado a terceiro para avaliação (opcional) |
| `external_quote` | `in_analysis` ou `awaiting_approval` | retorno do terceiro |
| `in_analysis` | `awaiting_approval` (aguardando aprovação) | orçamento pronto, enviado ao cliente |
| `awaiting_approval` | `approved` (aprovada) | cliente aceitou |
| `awaiting_approval` | `not_approved` (não aprovado) | orçamento ficou sem retorno do cliente por tempo suficiente — mudança manual do técnico, sem prazo automático |
| `approved` | `completed` (concluída) | serviço executado |
| `open` / `in_analysis` / `external_quote` / `awaiting_approval` | `canceled` (cancelada) | a qualquer momento antes da aprovação, por decisão explícita (cliente não quer mais, ou a empresa decide encerrar) |
| `completed` | `warranty_repair` (garantia) | retrabalho dentro do prazo de garantia — **mesma OS**, não cria uma nova |
| `warranty_repair` | `completed` | retrabalho finalizado |

`completed` (fora do prazo de garantia), `canceled` e `not_approved` são os únicos estados sem
saída. A reabertura por `warranty_repair` existe justamente para a empresa medir quantos
retrabalhos aconteceram num período, sem perder o vínculo com a OS original.

`not_approved` existe separado de `canceled` porque, na prática, são causas diferentes: um
orçamento pode ficar meses sem resposta do cliente (o caso de `not_approved`, que a empresa quer
medir à parte — "quantos orçamentos não aprovados eu tive") sem que ninguém tenha de fato decidido
cancelar o serviço. Nenhum dos dois é reaberto — a necessidade real, confirmada pelo cliente, é
só poder **consultar** os dados da OS depois (`GET /orders/{id}` não depende do status), para o
caso de o cliente retomar contato meses depois perguntando sobre aquele orçamento.

## Peças, mão de obra e o cálculo do total

Corrige a decisão anterior ("valor da peça sempre obrigatório"). Casos reais levantados na
validação:

| Situação | Peças (`order_items.unit_price`) | Mão de obra (`orders.labor_cost`) |
|---|---|---|
| Cliente particular (mais comum) | preenchido | preenchido |
| Prefeitura (evita disparar licitação acima de um teto) | vazio — peça embutida | preenchido |
| Caso raro, só peça | preenchido | vazio |

`orders.total` é sempre calculado no backend: soma dos `order_items.unit_price` não nulos +
`labor_cost` (quando presente). A validação da OS recusa a gravação se **nenhum** valor
estiver presente (nem peça, nem mão de obra) — ver critério de aceite em `escopo-v1.md`.

## Equipamentos: catálogo por cliente

Corrige a decisão anterior (equipamento como texto livre por OS, limitado a 2). Confirmado na
validação: o equipamento passa a ser cadastrado uma vez por cliente (`equipments`, ver api
`/clients/{id}/equipments`) e reaproveitado entre OS's — sem limite de quantidade por OS. Ao
criar uma OS (`POST /orders`), cada item de `equipments` no payload é ou uma referência a um
equipamento já cadastrado (`equipment_id`) ou os dados de um equipamento novo, que o backend
cadastra no catálogo do cliente na mesma transação (ver `OrderEquipmentInput` no OpenAPI).

`serial_number` repetido no mesmo cliente **não é bloqueado** (sem constraint `unique`) — o aviso
ao técnico é responsabilidade do frontend, comparando contra a lista já carregada do cliente
antes de enviar. Número de série às vezes é digitado errado ou fica em branco; bloquear geraria
fricção maior do que o problema que resolve.

### Snapshot do equipamento na OS (decisão da Fase 2)

`order_equipments` guarda uma **cópia** dos dados do equipamento (`name`, `brand`, `model`,
`serial_number`, `asset_tag`, `accessories`) no momento em que a OS é criada, além do vínculo
`equipment_id`. Editar o cadastro do equipamento no catálogo depois **não** reescreve OS's
antigas — o histórico fica fiel ao que foi atendido na época, mesmo que o cadastro seja corrigido
depois. `equipment_id` continua existindo para navegação até o catálogo e para o filtro
`GET /orders?equipment_id=X` da tela de histórico (ver `OrderEquipmentSnapshot` no OpenAPI).
Mesmo padrão de "snapshot de linha de pedido" usado em qualquer sistema de vendas — o preço do
produto na nota não muda se o catálogo mudar depois.

## Catálogo global de modelos de equipamento (api#101)

Pedido do cliente: parar de redigitar nome/marca/modelo a cada unidade física. "Ultrassom /
Sonopus / XYZ-100" vira **uma** entrada em `equipment_models`, global (compartilhada entre todos
os clientes, igual ao catálogo de acessórios do api#92), e o cadastro do equipamento do cliente
guarda só o que é daquela unidade: número de série, patrimônio, acessórios.

Dois níveis de catálogo, que é fácil confundir:

- `equipment_models` — **global**, o modelo do aparelho. Reaproveitado por qualquer cliente.
- `equipments` — **por cliente**, a unidade física que está lá na clínica (a seção acima).

`equipments` aponta pro modelo por `equipment_model_id`, mas **continua guardando
`name`/`brand`/`model`** — não é redundância esquecida, é necessidade: `equipments` é uma projeção
reconstruída por `event-sourcing:replay`, e os eventos gravados antes do api#101 só têm o trio
como texto, sem id de catálogo nenhum. Sem as colunas, o projector teria que criar entrada de
catálogo durante um replay (ou seja: gravar evento enquanto relê o event store). O trio é sempre
escrito a partir da entrada do catálogo resolvida — mesmo raciocínio FK+snapshot de
`order_equipments` acima.

Nome de modelo repetido **não é bloqueado** (sem `unique`), mesma decisão do `serial_number` e do
nome de acessório: o seletor do frontend oferece o que já existe antes de deixar cadastrar um novo.

### Manutenção do catálogo (api#109)

Até o api#112, o catálogo era alimentado sozinho — todo equipamento salvo com marca/modelo digitados
cria uma entrada —, então erro de digitação e dado de teste entravam e ficariam pra sempre. Daí `PUT`
e `DELETE` em `/equipment-models/{id}`, com duas regras que valem a pena saber de cor:

- **Corrigir uma entrada corrige os equipamentos que a usam.** É o que se espera de "consertei no
  catálogo": o typo some da tela do equipamento também. **OS já emitidas não mudam** — a seção
  acima explica por quê.
- **Remover é recusado com 409 enquanto algum equipamento usar o modelo.** A entrada sobrevive de
  propósito à remoção de uma unidade física (o "cadastrou uma vez, usa em todos" que o cliente
  pediu), então ela só sai quando ninguém mais aponta pra ela.

O catálogo de **acessórios** (`PUT|DELETE /accessories/{id}`) segue as mesmas duas regras, com uma
diferença: corrigir o nome de um acessório **não precisa propagar nada** — ele nunca foi copiado,
`equipment_accessories` guarda só o id e o nome vem pela relação. O que os dois casos têm em comum é
invalidar a listagem de equipamentos, que embute esses textos no cache.

### Seleção obrigatória — fecha a causa-raiz (api#112, issue #110)

`POST/PUT /clients/{id}/equipments` deixou de aceitar `name`/`brand`/`model` como texto livre.
`equipment_model_id` é obrigatório, e o `EquipmentController` lê o trio direto do `EquipmentModel`
encontrado — o método que procurava por texto e cadastrava uma entrada nova quando não achava
(`resolveEquipmentModel`) foi removido, não só desviado.

Isso fecha a causa-raiz registrada na #110: a única porta de entrada do catálogo agora é
`POST /equipment-models`, uma ação deliberada, nunca mais um efeito colateral de salvar um
equipamento. Equipamento cadastrado antes disso e sem vínculo (o backfill do api#101 não achou
correspondência) precisa ganhar um vínculo na próxima edição — a mesma validação vale pro `PUT`,
então não tem como salvar sem escolher um modelo.

## Fotos do equipamento (api#102)

Pedido do cliente: registrar "a forma com que a gente recebe o aparelho, e pra não ter divergência
também na saída". Fotos ficam no **cadastro do equipamento**, não na OS.

Endpoints próprios (`/clients/{id}/equipments/{equipmentId}/photos`), **fora** do payload de
`GET /clients/{id}/equipments`, por dois motivos: aquela listagem é cacheada por uma hora — e a URL
da foto é assinada com validade de 30 minutos, então ficaria vencida no cache — e porque objeto
aninhado novo dentro de um payload serializado é a classe de bug do api#99 (ver `CLAUDE.md`).

**Exibição via URL assinada — a única rota do projeto fora do `auth:sanctum`.** Uma tag `<img>` não
tem como mandar header `Authorization`, então a credencial é a assinatura da própria URL
(`URL::temporarySignedRoute`, 30 min), e o `GET /equipment-photos/{photoId}` é protegido pelo
middleware `signed`. Sem assinatura válida: 403. A alternativa considerada era o frontend baixar
cada foto como blob pelo `HttpClient` (passando pelo interceptor que já existe) e gerar `objectURL`
— mais fechado, porém com ciclo de vida de URL pra gerenciar em cada `<img>`; se o requisito de
sigilo das fotos apertar, é pra lá que se move.

O arquivo é gravado no disco **antes** do evento, que guarda só o caminho e os metadados — binário
nunca entra no event store. Apagar o arquivo é trabalho de Reactor, nunca de Projector (ver
`architecture.md` § Projectors síncronos, Reactors em fila).

Aceita jpeg/png/webp até 8 MB. **HEIC, o padrão do iPhone, ainda não** — converter exigiria imagick
no runtime; é o primeiro ajuste a fazer se o técnico usar iPhone e reclamar.

## Notificação automática (e-mail + WhatsApp)

Novo na validação: ao criar a OS com sucesso, o backend gera o PDF e dispara o envio de uma
cópia ao cliente por e-mail e por WhatsApp, sem ação do técnico.

- **E-mail**: `Illuminate\Mail`, enfileirado na fila `database` já prevista em `ambientes.md` —
  evita que a criação da OS espere o envio para responder.
- **WhatsApp**: recomendação é a **API oficial do WhatsApp Cloud (Meta)**, não um revendedor
  não-oficial — gratuita até um volume razoável de mensagens e sem dependência de terceiro.
  Exige verificação de conta comercial da Med Fusion na Meta, um passo manual que só o cliente
  pode fazer (ver `ambientes.md` para as variáveis de ambiente correspondentes). Enfileirado do
  mesmo jeito que o e-mail.
- **Confirmado na Fase 2**: o número de WhatsApp usado é `clients.phone` — sem campo dedicado.
  Se um cliente específico não tiver WhatsApp nesse número, o envio falha silenciosamente e cai
  no fluxo de reenvio manual.
- Falha no envio (e-mail ou WhatsApp) não deve impedir a criação da OS — registrar o erro e
  permitir reenvio manual depois é preferível a bloquear o fluxo principal por causa de um canal
  de notificação fora do ar.
- **Reenvio manual** (decidido na Fase 3): `POST /orders/{id}/notify` dispara os mesmos jobs de
  notificação novamente — reenvia o PDF já existente (gera um se ainda não houver), não cria uma
  cópia nova. Resposta `202` — processado de forma assíncrona pela fila `database`.

### WhatsApp exige um modelo de mensagem aprovado pela Meta

Como a Med Fusion inicia a conversa (o cliente não mandou mensagem antes), a API não permite
mensagem livre — é obrigatório um **modelo (template) pré-aprovado**, categoria **Utilitário**
(transação/pós-venda). Rascunho para submissão no Meta Business Manager:

```
Nome: ordem_servico_criada
Categoria: Utilitário (Utility)
Idioma: pt_BR
Cabeçalho: Documento (o PDF da OS, anexado dinamicamente — 100 MB de limite, bem acima do
           tamanho real de uma OS)
Corpo: "Olá {{1}}, segue a Ordem de Serviço nº {{2}} da Med Fusion, referente ao atendimento
        em {{3}}. Qualquer dúvida, estamos à disposição."
Rodapé: "Med Fusion Manutenção e Venda Clínica Hospitalar"
```

**Custo, a partir de 01/10/2026**: a Meta passa a cobrar por mensagens de template Utilitário
mesmo dentro da janela de 24h de atendimento — não é mais gratuito nesse cenário. Isso é custo
operacional recorrente da Med Fusion, não do desenvolvimento; vale registrar na proposta comercial
se ainda não estiver.

Dois passos manuais, só o cliente pode fazer, e bloqueiam **apenas** esta funcionalidade
(não o resto da v1): verificar a conta comercial da Med Fusion no Meta for Developers, e
submeter o modelo acima para aprovação (issue api #65 segue aberta até isso acontecer).

## Tabela de correspondência português → inglês

Nomes de campo no banco/API (não confundir com os valores gravados neles, que continuam em
português — ex.: `reported_defect` guarda o texto "Equipamento sem funções operacionais").

| Português (planilha/conversa) | Campo (inglês) | Tabela |
|---|---|---|
| Tipo de pessoa (física/jurídica) | `person_type` | `clients` |
| Nome / Razão social | `name` | `clients` |
| Nome fantasia | `trade_name` | `clients` |
| CPF/CNPJ | `tax_id` | `clients` |
| Inscrição estadual | `state_registration` | `clients` |
| Solicitante | `requester` | `clients` |
| Setor | `department` | `clients` |
| Telefone | `phone` | `clients` |
| E-mail | `email` | `clients` |
| Endereço | `address` | `clients` |
| Cidade | `city` | `clients` |
| UF | `state` | `clients` |
| CEP | `postal_code` | `clients` |
| Equipamento (nome/tipo) | `name` | `equipments`, `order_equipments`, `equipment_models` |
| Marca | `brand` | `equipments`, `order_equipments`, `equipment_models` |
| Modelo (texto do aparelho) | `model` | `equipments`, `order_equipments`, `equipment_models` |
| Modelo (entrada do catálogo global) | `equipment_model` | `equipment_models`, `equipments.equipment_model_id` |
| Número de série | `serial_number` | `equipments`, `order_equipments` |
| Patrimônio | `asset_tag` | `equipments`, `order_equipments` |
| Acessórios | `accessories` | `equipments`, `order_equipments` |
| Número (da OS) | `number` | `orders` |
| Data | `date` | `orders` |
| Retirado | `picked_up` | `orders` |
| Garantia (checkbox) | `warranty` | `orders` |
| Treinamento técnico | `technical_training` | `orders` |
| Orç. local | `on_site_quote` | `orders` |
| Locação | `rental` | `orders` |
| Defeito apresentado | `reported_defect` | `orders` |
| Manutenção a aplicar | `maintenance_plan` | `orders` |
| Observação | `notes` | `orders` |
| Forma de pagamento | `payment_method` | `orders` |
| Garantia (prazo) | `warranty_period` | `orders` |
| Validade da proposta | `proposal_validity` | `orders` |
| Valor da mão de obra | `labor_cost` | `orders` |
| Nº do certificado | `certificate_number` | `orders` |
| Quantidade | `quantity` | `order_items` |
| Descrição | `description` | `order_items` |
| Valor unitário | `unit_price` | `order_items` |
