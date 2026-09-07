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
