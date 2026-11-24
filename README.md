# Volmaster CRM

CRM para oficinas de caminhões a diesel, construído sobre o [Krayin CRM](https://github.com/krayin/laravel-crm)
(Laravel 12, PHP 8.3). Ordens de serviço em etapas (Cadastro do cliente, Ordem de Serviço, Diagnóstico,
Orçamento, Negociação, Execução, Teste, Faturamento, Pós-venda), piloto de indicadores e alertas,
perfis da mentoria, busca de cliente, anotações e anexos, quadro de pátio e testes automatizados.

## Estrutura

| Pasta | O que tem |
|---|---|
| `volmaster crm/` | Aplicação Laravel/Krayin: código, migrations, testes. O que é próprio da Volmaster fica em `volmaster crm/packages/Webkul/` (`ServiceOrder`, `Mechanic`, `Vehicle`, `PartInventory`, `Fiscal`, `Portal`, `Tenant`). |
| `volmaster-admin-app/` | App móvel (Capacitor / Android) de administração. |
| `ajustes-roteiro-18-11/` | Especificações dos ajustes pedidos no roteiro de 18/11/2026. |

## Rodar em desenvolvimento

```bash
cd "volmaster crm"
composer install
cp .env.example .env
php artisan key:generate
# configure o banco no .env
php artisan migrate
php artisan serve
```

Testes: `php artisan test` (Pest). Estilo: `./vendor/bin/pint`.

## Segurança

- **Nunca** versione o `.env`, logs, cookies, dumps de banco nem fotos e anexos de clientes.
- As chaves dos arquivos de `public/` (`migrate-safe.php`, `deploy-once.php`, `diag.php`,
  `criar-logins-demo.php`) estão como `TROQUE-ESTA-CHAVE`: defina a sua antes de colocar no servidor
  e **apague esses arquivos do servidor depois de usar**.
