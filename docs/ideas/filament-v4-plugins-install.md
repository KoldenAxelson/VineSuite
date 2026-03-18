# Filament v4 Plugin Installation — CLI Commands

Run these inside Docker, one at a time:

```bash
# 1. Apex Charts — fermentation curves, sparklines, production charts
docker compose exec app composer require leandrocfe/filament-apex-charts:"^5.0" --no-scripts

# 2. FullCalendar — work order calendar (replaces hand-built blade calendar)
docker compose exec app composer require saade/filament-fullcalendar:"^4.0" --no-scripts

# 3. After both install successfully, run the post-install scripts
docker compose exec app php artisan package:discover --ansi
docker compose exec app php artisan filament:upgrade
docker compose exec app php artisan view:clear
```

Note: Activity Timeline plugin deferred — our custom timeline in Lot Traceability works fine and the plugin requires Spatie Activitylog which would add complexity. We can revisit later.
