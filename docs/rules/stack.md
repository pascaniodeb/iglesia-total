# Stack and environment

## Stack

- Laravel 13, PHP 8.5, Inertia 3, React 19, TypeScript, Tailwind CSS v4, shadcn (new-york, `components.json`).
- Bundler/tooling: **Vite+** (`vite-plus`, see https://viteplus.dev/guide/). The `vp` binary is not on the global PATH: always go through the Bun scripts.

| Task                               | Command                               |
| ---------------------------------- | ------------------------------------- |
| Dev server (client + SSR with HMR) | `bun run dev`                         |
| Production build                   | `bun run build`                       |
| Production build with SSR bundle   | `bun run build:ssr`                   |
| Format + lint                      | `bun run check` / `bun run check:fix` |
| Types                              | `bun run types:check`                 |

- Routes and controller actions are called from the frontend through **Wayfinder** (`@/actions`, `@/routes`), never with hardcoded URLs.
- Generated folders (`resources/js/actions`, `routes`, `wayfinder`) and `components/ui/*` are excluded from lint/format.

## Database

- **PostgreSQL 18, port 5433** (Homebrew service `postgresql@18`) is the database of this app. Port 5432 (Postgres.app) hosts the **old system** database `iglesiatotal-db`, used only as the read-only source of the legacy import; the app never connects to it for anything else.
- Database `iglesiatotal`, owned by the dedicated role `iglesiatotal_app`. Credentials live only in `.env` (git-ignored); never commit them.
- Tests run on PostgreSQL: database `iglesiatotal_test` on the same server, same role. `phpunit.xml` only sets the connection and database name; host, user and password come from `.env`.
- Schema changes go through migrations created with `php artisan make:migration`.

## Environment

- `.env.example` documents the variables; `.env` is local.
- The Inertia SSR URL is `http://127.0.0.1:13714` (`config/inertia.php`).
- Local ports: the app is served on **8010** (`SERVER_PORT` and `APP_URL` in `.env`; port 8000 belongs to another project), Vite on **5183** (`server.port` in `vite.config.ts` with `strictPort`; 5173 belongs to another project), PostgreSQL on 5433. `composer run dev` (`php artisan dev`) starts the server, queue, logs and Vite and reads `SERVER_PORT`; if you run `php artisan serve` by hand it reads it too.
