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

## Status da OS — transições **[PROPOSTO, validação de 07/09/2026]**

Fluxo ampliado a partir da resposta do cliente à validação de escopo; ainda não confirmado
palavra por palavra (ver `escopo-v1.md` § Status da OS e `CONTEXT.md` § Validação com o cliente).

| De | Para | Quando |
|---|---|---|
| `aberta` | `em_analise` | diagnóstico começou |
| `em_analise` | `orcamento_externo` | enviado a terceiro para avaliação (opcional) |
| `orcamento_externo` | `em_analise` ou `aguardando_aprovacao` | retorno do terceiro |
| `em_analise` | `aguardando_aprovacao` | orçamento pronto, enviado ao cliente |
| `aguardando_aprovacao` | `aprovada` | cliente aceitou |
| `aprovada` | `concluida` | serviço executado |
| `aberta` / `em_analise` / `orcamento_externo` / `aguardando_aprovacao` | `cancelada` | a qualquer momento antes da aprovação |
| `concluida` | `garantia` | retrabalho dentro do prazo de garantia — **mesma OS**, não cria uma nova |
| `garantia` | `concluida` | retrabalho finalizado |

`concluida` (fora do prazo de garantia) e `cancelada` são os únicos estados sem saída. A
reabertura por `garantia` existe justamente para a empresa medir quantos retrabalhos aconteceram
num período, sem perder o vínculo com a OS original.

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
