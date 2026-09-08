# Convenções da API

> Complementa [`openapi.yaml`](./openapi.yaml) (issue [#21](https://github.com/celsojuniorsfs/medfusion-os-api/issues/21)).
> Insumo direto das issues [#28](https://github.com/celsojuniorsfs/medfusion-os-api/issues/28)
> (API Resources e formato de erro) e [#29](https://github.com/celsojuniorsfs/medfusion-os-api/issues/29)
> (estratégia Sanctum).

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
- Sem expiração automática na v1 (`expiration` do Sanctum fica `null`). Revisão de segurança
  fica para o backlog pós-v1, junto com múltiplos papéis de usuário.
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
  `OrderService` (ver api #44) como uma exceção própria (`DuplicateOrderNumberException`),
  capturada no `Handler` e traduzida para `{ "message": "..." }` com status 409.
- **Demais erros** (`401`, `403`, `404`, `500`): `{ "message": "..." }`, usando o tratamento
  padrão de exceções do Laravel — nenhuma customização necessária além de garantir que
  `APP_DEBUG=false` em produção não vaze stack trace (ver `ambientes.md`).

## Concorrência na numeração da OS

Duas requisições simultâneas de criação não podem obter o mesmo número. Estratégia (detalhada na
issue api #23, F2):

1. Constraint `unique` em `orders.numero` no banco — a garantia real está aqui, não na aplicação.
2. `GET /orders/next-number` é apenas uma **sugestão de UI**; o valor não é reservado.
3. Ao salvar, a criação roda dentro de uma transação; se a constraint `unique` disparar, a
   aplicação captura a exceção de integridade e responde `409` (ver acima) em vez de vazar um erro
   500 de SQL.

## Status da OS — transições

Fluxo fechado nas duas rodadas de validação de escopo com o cliente (ver `escopo-v1.md` §
Status da OS e `CONTEXT.md` § Validação com o cliente).

| De | Para | Quando |
|---|---|---|
| `aberta` | `em_analise` | diagnóstico começou |
| `em_analise` | `orcamento_externo` | enviado a terceiro para avaliação (opcional) |
| `orcamento_externo` | `em_analise` ou `aguardando_aprovacao` | retorno do terceiro |
| `em_analise` | `aguardando_aprovacao` | orçamento pronto, enviado ao cliente |
| `aguardando_aprovacao` | `aprovada` | cliente aceitou |
| `aguardando_aprovacao` | `nao_aprovado` | orçamento ficou sem retorno do cliente por tempo suficiente — mudança manual do técnico, sem prazo automático |
| `aprovada` | `concluida` | serviço executado |
| `aberta` / `em_analise` / `orcamento_externo` / `aguardando_aprovacao` | `cancelada` | a qualquer momento antes da aprovação, por decisão explícita (cliente não quer mais, ou a empresa decide encerrar) |
| `concluida` | `garantia` | retrabalho dentro do prazo de garantia — **mesma OS**, não cria uma nova |
| `garantia` | `concluida` | retrabalho finalizado |

`concluida` (fora do prazo de garantia), `cancelada` e `nao_aprovado` são os únicos estados sem
saída. A reabertura por `garantia` existe justamente para a empresa medir quantos retrabalhos
aconteceram num período, sem perder o vínculo com a OS original.

`nao_aprovado` existe separado de `cancelada` porque, na prática, são causas diferentes: um
orçamento pode ficar meses sem resposta do cliente (o caso de `nao_aprovado`, que a empresa quer
medir à parte — "quantos orçamentos não aprovados eu tive") sem que ninguém tenha de fato decidido
cancelar o serviço. Nenhum dos dois é reaberto — a necessidade real, confirmada pelo cliente, é
só poder **consultar** os dados da OS depois (`GET /orders/{id}` não depende do status), para o
caso de o cliente retomar contato meses depois perguntando sobre aquele orçamento.

## Peças, mão de obra e o cálculo do total

Corrige a decisão anterior ("valor da peça sempre obrigatório"). Casos reais levantados na
validação:

| Situação | Peças (`order_items.valor_unitario`) | Mão de obra (`orders.valor_mao_obra`) |
|---|---|---|
| Cliente particular (mais comum) | preenchido | preenchido |
| Prefeitura (evita disparar licitação acima de um teto) | vazio — peça embutida | preenchido |
| Caso raro, só peça | preenchido | vazio |

`orders.total` é sempre calculado no backend: soma dos `order_items.valor_unitario` não nulos +
`valor_mao_obra` (quando presente). A validação da OS recusa a gravação se **nenhum** valor
estiver presente (nem peça, nem mão de obra) — ver critério de aceite em `escopo-v1.md`.

## Equipamentos: catálogo por cliente

Corrige a decisão anterior (equipamento como texto livre por OS, limitado a 2). Confirmado na
validação: o equipamento passa a ser cadastrado uma vez por cliente (`equipments`, ver api
`/clients/{id}/equipments`) e reaproveitado entre OS's — sem limite de quantidade por OS. Ao
criar uma OS (`POST /orders`), cada item de `equipments` no payload é ou uma referência a um
equipamento já cadastrado (`equipment_id`) ou os dados de um equipamento novo, que o backend
cadastra no catálogo do cliente na mesma transação (ver `OrderEquipmentInput` no OpenAPI).

`numero_serie` repetido no mesmo cliente **não é bloqueado** (sem constraint `unique`) — o aviso
ao técnico é responsabilidade do frontend, comparando contra a lista já carregada do cliente
antes de enviar. Número de série às vezes é digitado errado ou fica em branco; bloquear geraria
fricção maior do que o problema que resolve.

### Snapshot do equipamento na OS (decisão da Fase 2)

`order_equipments` guarda uma **cópia** dos dados do equipamento (`equipamento`, `marca`,
`modelo`, `numero_serie`, `patrimonio`, `acessorios`) no momento em que a OS é criada, além do
vínculo `equipment_id`. Editar o cadastro do equipamento no catálogo depois **não** reescreve
OS's antigas — o histórico fica fiel ao que foi atendido na época, mesmo que o cadastro seja
corrigido depois. `equipment_id` continua existindo para navegação até o catálogo e para o
filtro `GET /orders?equipment_id=X` da tela de histórico (ver `OrderEquipmentSnapshot` no
OpenAPI). Mesmo padrão de "snapshot de linha de pedido" usado em qualquer sistema de vendas —
o preço do produto na nota não muda se o catálogo mudar depois.

## Notificação automática (e-mail + WhatsApp)

Novo na validação: ao criar a OS com sucesso, o backend gera o PDF e dispara o envio de uma
cópia ao cliente por e-mail e por WhatsApp, sem ação do técnico.

**Confirmado na Fase 2**: o número de WhatsApp usado é `clients.telefone` — sem campo dedicado.
Se um cliente específico não tiver WhatsApp nesse número, o envio falha silenciosamente e cai no
fluxo de reenvio manual.

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
- Falha no envio (e-mail ou WhatsApp) não deve impedir a criação da OS — registrar o erro e
  permitir reenvio manual depois é preferível a bloquear o fluxo principal por causa de um canal
  de notificação fora do ar.
- **Reenvio manual** (decidido na Fase 3): `POST /orders/{id}/notify` dispara os mesmos jobs de
  notificação novamente — reenvia o PDF já existente (gera um se ainda não houver), não cria uma
  cópia nova. Resposta `202` — processado de forma assíncrona pela fila `database`.
