# Ambientes e variáveis

> Fecha a issue [#22 — Definir ambientes e variáveis (.env) de dev/staging/produção](https://github.com/celsojuniorsfs/medfusion-os-api/issues/22).
> Dois ambientes: **local** e **produção**. Sem staging — a v1 é operada por poucos técnicos e o
> custo de um terceiro ambiente completo (banco + compute) não se paga ainda; revisitar se o
> volume de OS crescer.

## Local

- **Backend inteiro em Docker** ([Laravel Sail](https://laravel.com/docs/sail), decidido em
  10/09/2026): `docker compose up -d` sobe `laravel.test` (`http://localhost:8000`) e `mysql`
  (MySQL 8.4, mesma major da produção — para não caçar bugs de dialeto SQL depois). Comandos
  artisan/composer rodam via `docker compose exec laravel.test ...` (ver README).
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
- **Object Storage**: guarda os PDFs gerados (ver `openapi.yaml`, `POST /orders/{id}/pdf`). O
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
| `CACHE_STORE` | `database` na v1 — sem Redis/KV Store contratado ainda; revisitar se latência de cache virar gargalo |
| `QUEUE_CONNECTION` | `database` — usada para enfileirar o envio de e-mail/WhatsApp da OS (ver abaixo); PDF continua gerado de forma síncrona no request |

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

### CORS

`config/cors.php`: liberar apenas `/api/*`, com:

- `allowed_origins`: domínio de produção da Vercel + padrão de preview `https://*.vercel.app`
  (via `allowed_origins_patterns`, já que o Laravel não faz wildcard em `allowed_origins`).
- `supports_credentials: false` — não há cookies cross-site, é tudo Bearer token.

### Backups e disponibilidade

- Backup diário do cluster MySQL, retenção padrão de **7 dias** (suficiente para a v1; aumentar
  se a operação exigir auditoria mais longa).
- Scale-to-zero **desligado** em produção (ver acima); mantido **ligado** em qualquer cluster de
  teste/local para reduzir custo.

### Seed inicial de produção

Roda uma vez, manualmente, após o primeiro deploy bem-sucedido (issue api #57):

- Usuário técnico inicial (e-mail/senha definidos fora do repositório, nunca commitados).
- Contador de numeração da OS inicializado em **1336**, para o primeiro `next-number` da produção
  responder `1337`.
