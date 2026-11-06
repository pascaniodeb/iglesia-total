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

- **PostgreSQL 18, port 5433** (Homebrew service `postgresql@18`). Port 5432 is a different server; do not use it for this project.
- Database `iglesiatotal`, owned by the dedicated role `iglesiatotal_app`. Credentials live only in `.env` (git-ignored); never commit them.
- Tests use SQLite in memory (`phpunit.xml`), so no test database is needed.
- Schema changes go through migrations created with `php artisan make:migration`.

## Environment

- `.env.example` documents the variables; `.env` is local.
- The Inertia SSR URL is `http://127.0.0.1:13714` (`config/inertia.php`).
