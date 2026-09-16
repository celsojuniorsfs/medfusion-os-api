# Ambientes e variáveis

> Fecha a issue [#22 — Definir ambientes e variáveis (.env) de dev/staging/produção](https://github.com/celsojuniorsfs/medfusion-os-api/issues/22).
> Dois ambientes: **local** e **produção**. Sem staging — a v1 é operada por poucos técnicos e o
> custo de um terceiro ambiente completo (banco + compute) não se paga ainda; revisitar se o
> volume de OS crescer.

## Local

- **Backend inteiro em Docker** ([Laravel Sail](https://laravel.com/docs/sail), decidido em
  10/09/2026): `docker compose up -d` sobe `app` (`http://localhost:8000`) e `mysql`
  (MySQL 8.4, mesma major da produção — para não caçar bugs de dialeto SQL depois). Comandos
  artisan/composer rodam via `docker compose exec app ...` (ver README). Serviço renomeado de
  `laravel.test` (nome padrão do Sail) para `app` em 14/09/2026 — só o nome no Compose muda, a
  imagem continua `sail-8.4/app`.
- Mailpit, um worker de fila dedicado e o Adminer ficam atrás do profile `extra`
  (`docker compose --profile extra up -d`) — sem uso real ainda (fila e e-mail são só
  documentados, a notificação automática da OS não está implementada), então não rodam por
  padrão.
- `.env` a partir de [`.env.example`](../.env.example) (versionado nesta issue).
- Frontend: `ng serve`, `environment.ts` apontando `apiUrl: 'http://localhost:8000/api/v1'` —
  roda fora do Docker, sem mudança.
- CORS liberado para `http://localhost:4200` (porta padrão do Angular).

## Produção — Laravel Cloud

Deploy automático a partir da branch `main` do GitHub. Recursos anexados ao environment
`production`:

- **Compute**: runtime **PHP 8.4** (a plataforma já traz `dom`, `mbstring`, `gd`, `zlib`, `iconv`
  — tudo que o dompdf precisa — disponíveis para qualquer versão suportada).
- **Banco**: cluster **Laravel MySQL**, tamanho **Pro** (always-on — sem scale-to-zero em
  produção, para não pagar o primeiro acesso do dia com uma consulta lenta enquanto o banco
  acorda).
- **Object Storage**: guarda os PDFs gerados (ver `openapi.yaml`, `POST /orders/{id}/pdf`) e as
  **fotos dos equipamentos** (api#102, já em uso — ao contrário do PDF, que ainda não existe). O
  filesystem do compute é **efêmero** — não sobrevive a deploy nem é compartilhado entre réplicas
  — então nenhum arquivo de aplicação pode depender de `storage/app` além do tempo de uma
  requisição.

### Build e deploy commands

```
build:  composer install --no-dev
deploy: php artisan migrate --force
```

Explicitamente **fora** dos comandos de deploy, por não se aplicarem ou já serem cuidados pela
plataforma:

- `php artisan storage:link` — o link não sobrevive ao próximo deploy (filesystem efêmero); usar
  Object Storage em vez de disco local para qualquer arquivo persistente.
- `php artisan optimize:clear` — pode causar comportamento inesperado com a fila; evitar.
- `php artisan queue:restart` / `horizon:terminate` — a plataforma já reinicia workers a cada
  deploy.

`php artisan config:cache` roda como parte do build, não do deploy.

### Variáveis de ambiente

**Injetadas automaticamente pelo Laravel Cloud** (nunca definir à mão; sobrescrever quebra a
conexão):

| Variável | Origem |
|---|---|
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | cluster Laravel MySQL anexado |
| credenciais de Object Storage (`*_STORAGE_*`, conforme o driver S3-compatível da plataforma) | Object Storage anexado |

**Definidas no painel do environment**:

| Variável | Valor em produção |
|---|---|
| `APP_ENV` | `production` |
| `APP_KEY` | gerado uma vez com `php artisan key:generate --show` e colado no painel |
| `APP_DEBUG` | `false` — nunca `true` em produção (vazaria stack trace nas respostas de erro) |
| `APP_URL` | URL pública da API (`https://<domínio>.laravel.cloud` até domínio próprio ser configurado na F6) |
| `FRONTEND_URL` | URL de produção do frontend na Vercel, usada para montar o CORS e links |
| `FILESYSTEM_DISK` | driver do Object Storage (S3-compatível) |
| `SESSION_DRIVER` | `database` (evita depender do filesystem efêmero) |
| `CACHE_STORE` | `redis` — **pré-requisito de deploy** (ver aviso abaixo), não mais `database`. Nome do driver do Laravel; o servidor real anexado é Valkey, não Redis (ver `docs/architecture.md` § Cache) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | credenciais do Valkey anexado ao environment (KV Store do Laravel Cloud, ou serviço equivalente) — nome de variável `REDIS_*` por convenção do driver, aponta pro Valkey |
| `QUEUE_CONNECTION` | `database` — usada para enfileirar o envio de e-mail/WhatsApp da OS (ver abaixo); PDF continua gerado de forma síncrona no request |
| `PULSE_ALLOWED_EMAILS` | e-mails (separados por vírgula) autorizados a abrir `/pulse` — ver "Monitoramento" abaixo |

**Sobre o cache de listagens** (ver `docs/architecture.md` § Cache): o código usa
`Cache::increment()`/`Cache::get()` — operações simples de uma chave só, suportadas por
qualquer driver (`database` incluído, ao contrário do antigo esquema com `Cache::tags()`, que
exigia Redis/Valkey e quebrava com 500 em `database`). Redis/Valkey (já anexado ao environment
de produção) continua sendo o certo por performance — `remember()` num banco relacional é bem
mais lento sob carga do que num key-value store — mas deixou de ser um pré-requisito rígido pra
não quebrar nada.

**Frontend (Vercel)** — não são variáveis do Laravel, mas fecham o par: `environment.ts` /
`environment.prod.ts` do Angular trazem `apiUrl` apontando para a API de cada ambiente.

### Notificação automática (novo — validação de 07/09/2026)

Ao criar a OS, o backend envia automaticamente uma cópia do PDF ao cliente por e-mail e WhatsApp
(ver `api-conventions.md`). Variáveis adicionais, **definidas no painel do environment** (mesma
origem que as demais — nada disso é injetado automaticamente pela plataforma):

| Variável | Descrição |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | credenciais do provedor de e-mail transacional escolhido |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | remetente exibido ao cliente |
| `WHATSAPP_PHONE_NUMBER_ID` | id do número de telefone comercial no WhatsApp Cloud API |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` | id da conta comercial (WABA) na Meta |
| `WHATSAPP_ACCESS_TOKEN` | token de acesso do app configurado no Meta for Developers |

**Dependência externa que não depende de código**: a Med Fusion precisa verificar uma conta
comercial no WhatsApp Cloud API (Meta for Developers) antes desta funcionalidade poder ir ao ar
— é um passo manual do lado do cliente, não algo resolvido só com configuração de ambiente.

**Segunda dependência (Fase 2 — Análise)**: a Meta exige um **modelo de mensagem aprovado**
(categoria Utilitário) para conversas iniciadas pela empresa — rascunho pronto para submissão em
`api-conventions.md` § Notificação automática. **A partir de 01/10/2026, esse tipo de mensagem
deixa de ser gratuito** mesmo dentro da janela de 24h — custo operacional recorrente a considerar
na proposta comercial com o cliente, não só custo de desenvolvimento.

### Monitoramento (Laravel Pulse — 12/09/2026)

Dashboard de APM em `/pulse`: requests lentas, queries lentas, exceções, filas, jobs lentos, cache
e uso por usuário. Grava nas mesmas tabelas do cluster MySQL de produção (prefixo `pulse_`), sem
recurso de infra adicional — sem Redis, sem worker/scheduler dedicado. Retenção padrão de 7 dias
(`PULSE_STORAGE_KEEP`), com trim automático por loteria a cada ingest.

Protegido por HTTP Basic Auth (contra a tabela `users` — não há tela de login de sessão neste app)
mais o gate `viewPulse` (`Modules/Identity/app/Providers/IdentityServiceProvider.php`), que em
produção só libera os e-mails listados em `PULSE_ALLOWED_EMAILS`. Sem conceito de papel/role na v1
(decisão da F3), então essa allowlist por env é a autorização.

O card "Servers" (CPU/memória/disco) foi removido do dashboard — depende do daemon `pulse:check`
rodando no servidor, e o compute do Laravel Cloud é efêmero e gerenciado pela plataforma; não faz
sentido medir isso aqui (ver `docs/architecture.md`).

### CORS

`config/cors.php`: liberar apenas `/api/*`, com:

- `allowed_origins`: domínio de produção da Vercel + padrão de preview
  `https://medfusion-os-web(-<hash-ou-branch>-<time>)?.vercel.app` (via `allowed_origins_patterns`,
  já que o Laravel não faz wildcard em `allowed_origins`) — escopo pelo nome do projeto
  ("medfusion-os-web"), não `*.vercel.app` genérico (corrigido no code review de 13/09/2026: o
  padrão antigo liberava qualquer app hospedado na Vercel, não só os previews deste projeto).
- `supports_credentials: false` — não há cookies cross-site, é tudo Bearer token.

### Backups e disponibilidade

- Backup diário do cluster MySQL, retenção padrão de **7 dias** (suficiente para a v1; aumentar
  se a operação exigir auditoria mais longa).
- Scale-to-zero **desligado** em produção (ver acima); mantido **ligado** em qualquer cluster de
  teste/local para reduzir custo.

### Seed inicial de produção

Roda uma vez, manualmente, após o primeiro deploy bem-sucedido (issue api #57):

- Usuário técnico inicial (e-mail/senha definidos fora do repositório, nunca commitados).

Numeração da OS **não** entra nesta lista — não existe passo de seed nem tabela de contador para
isso (ver api #44). O piso de **1336** está embutido no código
(`OrderService::nextNumber()`, `Modules/Orders/app/Application/OrderService.php`): com a tabela
`orders` vazia, `MAX(number)` é `null`, e `(null ?? 1336) + 1` já responde `1337` desde o primeiro
deploy, sem nenhuma ação manual.
