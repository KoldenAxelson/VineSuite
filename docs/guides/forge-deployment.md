# Forge Deployment Setup

> Status: Not yet provisioned
> Workflow: `.github/workflows/deploy.yml` (currently manual-only via `workflow_dispatch`)

---

## Overview

The deploy workflow is designed to run CI checks and then trigger a Laravel Forge deployment via webhook. It is currently disabled from auto-deploying on push to `main` to avoid errors until the staging server is provisioned.

Once Forge is ready, the workflow will:

1. Run the full CI pipeline (Pint, PHPStan, Pest) via `ci.yml`
2. On success, fire a POST request to the Forge deploy webhook
3. Forge pulls the latest code, runs migrations, clears caches, and restarts workers

---

## Prerequisites

Before enabling automatic deployments, the following must be in place:

### 1. Provision a Forge Server

- **Provider:** Your preferred cloud provider (DigitalOcean, AWS, Hetzner, etc.)
- **Stack:** PHP 8.4, PostgreSQL 16, Redis 7, Nginx
- **Site root:** `/home/forge/vinesuite.com/api/public`

### 2. Configure the Forge Site

- Create a new site in Forge pointed at the repository `KoldenAxelson/VineSuite`
- Set the web directory to `api/public`
- Set the deploy branch to `main`
- Under **Environment**, add all production `.env` variables (APP_KEY, DB credentials, Stripe keys, etc.)

### 3. Get the Deploy Webhook URL

- In Forge, go to the site → **Deployments** → copy the **Deploy Webhook URL**
- It looks like: `https://forge.laravel.com/servers/{id}/sites/{id}/deploy/http?token=...`

### 4. Add GitHub Secrets

Add these secrets in the GitHub repo (Settings → Secrets and variables → Actions):

| Secret | Value | Notes |
|--------|-------|-------|
| `FORGE_DEPLOY_WEBHOOK_URL` | The webhook URL from Forge | Used by deploy.yml |
| `APP_KEY_TESTING` | `base64:<32-byte-key>` | Used by CI Pest job. Generate with `php artisan key:generate --show` |

### 5. Create the GitHub Environment

- Go to repo Settings → Environments → New environment → name it `staging`
- Optionally add protection rules (required reviewers, wait timer, etc.)

---

## Enable Auto-Deploy

Once all prerequisites are met, update `.github/workflows/deploy.yml`:

```yaml
# Change this:
on:
  workflow_dispatch:

# To this:
on:
  push:
    branches: [main]
```

Commit and push. From that point on, every push to `main` that passes CI will automatically deploy to staging.

---

## Forge Deploy Script

Recommended deploy script to set in Forge (Site → Deploy Script):

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

When ready for production, duplicate the deploy workflow or add a second job:

- Trigger on tags (e.g., `v*`) or a `production` branch
- Use a separate `production` GitHub environment with required approvals
- Point to a different Forge server/site with production credentials
- Add a smoke test step after deployment to verify the app is responding
