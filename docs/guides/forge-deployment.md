# Forge Deployment Setup

> Status: Not yet provisioned
> Workflow: `.github/workflows/deploy.yml` (currently manual-only via `workflow_dispatch`)

The deploy workflow runs CI checks (Pint, PHPStan, Pest) then triggers a Laravel Forge deployment via webhook. Once staging is provisioned, it will pull code, run migrations, clear caches, and restart workers.

---

## Prerequisites

### 1. Provision a Forge Server

- **Stack:** PHP 8.4, PostgreSQL 16, Redis 7, Nginx
- **Site root:** `/home/forge/vinesuite.com/api/public`
- **Deploy branch:** `main`

### 2. Configure the Forge Site

- Repository: `KoldenAxelson/VineSuite`
- Web directory: `api/public`
- Under **Environment**, add production `.env` variables (APP_KEY, DB credentials, Stripe keys, etc.)

### 3. Get the Deploy Webhook URL

In Forge → site → **Deployments** → copy the **Deploy Webhook URL**. Format: `https://forge.laravel.com/servers/{id}/sites/{id}/deploy/http?token=...`

### 4. Add GitHub Secrets

In repo Settings → Secrets and variables → Actions:

| Secret | Value |
|--------|-------|
| `FORGE_DEPLOY_WEBHOOK_URL` | Webhook URL from Forge |
| `APP_KEY_TESTING` | `base64:<32-byte-key>` (generate with `php artisan key:generate --show`) |

### 5. Create GitHub Environment

Settings → Environments → New environment → name it `staging`. Optionally add protection rules.

---

## Enable Auto-Deploy

Once prerequisites are in place, update `.github/workflows/deploy.yml`:

```yaml
on:
  push:
    branches: [main]
```

Every push to `main` that passes CI will automatically deploy to staging.

---

## Forge Deploy Script

Recommended script in Forge (Site → Deploy Script):

```bash
cd /home/forge/vinesuite.com/api

git pull origin $FORGE_SITE_BRANCH

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan queue:restart

echo "Deployed successfully at $(date)"
```

---

## Future: Production Environment

When ready, duplicate the workflow or add a second job:

- Trigger on tags (e.g., `v*`) or a `production` branch
- Use separate `production` GitHub environment with required approvals
- Point to different Forge server/site with production credentials
- Add smoke test step after deployment
