# shopkeeper-laravel

A **Laravel (PHP)** port of [shopkeeper](https://github.com/GeneralHannes/shopkeeper)
(the original is Python/FastAPI). Same PostgreSQL schema, same self-contained frontend
(`resources/index.html`, served at `/`), same `/api` surface — a different backend.

- **Framework:** Laravel 13 (PHP 8.5)
- **DB:** PostgreSQL via the `DB` facade with raw SQL (no Eloquent models) — identical
  schema/migrations to the original, incl. `pg_trgm` fuzzy search and the
  `item_current_price` view.
- **AI:** Ollama (local), called over HTTP for `/api/parse`, `/api/parse-catalog`, `/api/assistant`.
- **Frontend:** the original vanilla-JS SPA, reused unchanged. Static assets in `public/static/`.

## Run

```bash
composer install
cp .env.example .env
php artisan key:generate
# point DB_* at your Postgres (host port 5434 by default)
php artisan shop:migrate        # applies database/sql/*.sql (tracked, run once each)
php artisan serve --host=0.0.0.0 --port=8765
```

Then open `http://<host>:8765`.

## How it maps to the original
- **Routes:** `routes/api.php` (auto-prefixed `/api`, no CSRF) + `routes/web.php` (`/` → SPA).
- **Auth:** `App\Http\Middleware\TokenAuth` — requires `WEB_TOKEN` via `X-Token`/`?token=` on
  `/api/*` (except `/api/auth`).
- **Data access:** `App\Support\Repo` mirrors the Python `repository.py` one-for-one.
- **Endpoints:** `App\Http\Controllers\ApiController` mirrors `web.py` (items, prices per kind,
  options, images (bytea), barcode + OFF/UPCitemdb lookup, search, sales/void, categories,
  brand/size, drink info, is_alcohol, meta, reports, and the AI endpoints).
- **Migrations:** the original raw `.sql` files in `database/sql/`, applied by the
  `php artisan shop:migrate` command (not Laravel's own migrator, since they carry their
  own `BEGIN…COMMIT`).

> Sessions/cache use the `file` driver (this app is token-based and stateless), so no extra
> Laravel tables are needed — only the shopkeeper schema.
