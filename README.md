# Med Fusion Manutenção e Venda Clínica Hospitalar Ltda.

Backend (Laravel API) do sistema de Ordem de Serviço. Consumido pelo frontend
Angular em [medfusion-os-web](https://github.com/celsojuniorsfs/medfusion-os-web).

## Stack

- **Framework**: Laravel + Sanctum (autenticação por token Bearer)
- **Arquitetura**: monólito modular ([`nwidart/laravel-modules`](https://laravelmodules.com/)),
  DDD-like, Clean Architecture, com
  [`spatie/laravel-event-sourcing`](https://spatie.be/docs/laravel-event-sourcing/v7/) como
  mecanismo de integração entre módulos — ver [`docs/architecture.md`](./docs/architecture.md)
- **PDF**: dompdf, gerado sob demanda e guardado no Laravel Cloud Object Storage
- **Banco**: Laravel MySQL (Laravel Cloud)
- **Deploy**: Laravel Cloud

## Estrutura

```
Modules/{Identity,Clients,Equipments,Orders}/     # módulos nwidart/laravel-modules
  app/
    Domain/          Agregado (AggregateRoot), Events/, Enums/, Exceptions/
    Application/     Actions invocáveis (casos de uso)
    Infrastructure/  Projectors/, Reactors/, ReadModels/ (Eloquent)
    Presentation/    Http/Controllers, Http/Requests, Http/Resources
    Providers/       <Módulo>ServiceProvider, RouteServiceProvider
  routes/api.php      Só nos módulos com endpoint HTTP (hoje só Identity)
  database/{migrations,seeders}/
```

Namespace raiz de cada módulo é `Modules\<Módulo>\`, não `App\Modules\`. Detalhes, a regra de
fronteira entre módulos e por que Projectors são registrados explicitamente (sem auto-discovery)
em [`docs/architecture.md`](./docs/architecture.md).

## Como rodar localmente

Todo o backend roda em Docker via [Laravel Sail](https://laravel.com/docs/sail) — não precisa de
PHP/Composer instalados na máquina, só Docker.

```bash
cp .env.example .env
# Preencher ADMIN_EMAIL / ADMIN_PASSWORD no .env antes do seed abaixo

docker compose up -d                                    # app (porta 8000) + mysql
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

A partir daqui, rode qualquer comando artisan/composer/pint prefixado com
`docker compose exec app` (ex.: `docker compose exec app php artisan test`).

Mailpit (captura e-mails, ainda sem uso — feature de notificação não implementada), um worker de
fila dedicado e o Adminer (UI do MySQL) ficam atrás de um profile, pra não pesar na máquina sem
necessidade:

```bash
docker compose --profile extra up -d   # + mailpit (:8025), queue, adminer (:8080)
```

Testar o login:

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"<ADMIN_EMAIL do .env>","password":"<ADMIN_PASSWORD do .env>"}'
```

Depois de corrigir um bug de projeção (ou adicionar uma coluna a um read model), reconstrua os
dados a partir dos eventos gravados:

```bash
docker compose exec app php artisan event-sourcing:replay
```

Métricas (duração/contagem de requests HTTP e de queries) são instrumentadas por
[OpenTelemetry](https://github.com/keepsuit/laravel-opentelemetry) e empurradas por OTLP pro
Grafana Cloud — **não há dashboard dentro da aplicação**, e o envio vem desligado por padrão
(`OTEL_SDK_DISABLED=true` no `.env.example`). Para ligar localmente, preencha as variáveis `OTEL_*`
do `.env` com as credenciais do seu stack no Grafana Cloud — passo a passo em
[`docs/ambientes.md`](./docs/ambientes.md) § Monitoramento. Para inspecionar sem conta nenhuma,
`OTEL_METRICS_EXPORTER=console` imprime as métricas no stdout.

## Documentação

- [`docs/architecture.md`](./docs/architecture.md) — monólito modular, DDD-like, Clean
  Architecture, Event Sourcing
- [`docs/openapi.yaml`](./docs/openapi.yaml) — contrato da API (OpenAPI 3.1), todos os endpoints
  sob `/api/v1`
- [`docs/api-conventions.md`](./docs/api-conventions.md) — versionamento, autenticação, formato
  de erro e API Resources
- [`docs/ambientes.md`](./docs/ambientes.md) — ambientes, variáveis de ambiente e configuração de
  deploy no Laravel Cloud
- [`.env.example`](./.env.example) — variáveis de ambiente para desenvolvimento local
- Escopo da v1 e critérios de aceite:
  [medfusion-os-web/docs/escopo-v1.md](https://github.com/celsojuniorsfs/medfusion-os-web/blob/main/docs/escopo-v1.md)
- Glossário de domínio:
  [medfusion-os-web/CONTEXT.md](https://github.com/celsojuniorsfs/medfusion-os-web/blob/main/CONTEXT.md)

## Acompanhamento

O backlog está organizado em issues e milestones por fase do ciclo de desenvolvimento
(Planejamento → Análise → Projeto → Programação → Testes → Implantação), tanto neste
repositório quanto no [medfusion-os-web](https://github.com/celsojuniorsfs/medfusion-os-web).
