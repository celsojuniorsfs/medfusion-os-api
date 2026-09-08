# Med Fusion Manutenção e Venda Clínica Hospitalar Ltda.

Backend (Laravel API) do sistema de Ordem de Serviço / Orçamento Técnico. Consumido pelo frontend
Angular em [medfusion-os-web](https://github.com/celsojuniorsfs/medfusion-os-web).

## Stack

- **Framework**: Laravel + Sanctum (autenticação por token Bearer)
- **PDF**: dompdf, gerado sob demanda e guardado no Laravel Cloud Object Storage
- **Banco**: Laravel MySQL (Laravel Cloud)
- **Deploy**: Laravel Cloud

## Como rodar localmente

```bash
composer install
cp .env.example .env
php artisan key:generate
# Preencher ADMIN_EMAIL / ADMIN_PASSWORD no .env antes do seed abaixo

docker compose up -d          # MySQL 8 (porta 3306; se já tiver algo nessa porta, mude
                               # DB_PORT no .env — o compose lê a mesma variável)
php artisan migrate --seed
php artisan serve             # http://localhost:8000
```

Testar o login:

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"<ADMIN_EMAIL do .env>","password":"<ADMIN_PASSWORD do .env>"}'
```

## Documentação

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
