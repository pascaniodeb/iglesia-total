# Workflow

## Rules

- **Work inline.** No sub-agents or delegated background workers on this project. All reading, writing and verification happens in the main session.
- **Visual work and business logic are separate.** Design-system work never carries domain logic, branding or domain copy from other projects.
- **Quality gates must be green before a change is considered done:**
    - `bun run check` (format + lint, Vite+)
    - `bun run types:check` (`tsc --noEmit`, strict flags, see [frontend.md](frontend.md))
    - `php artisan test --compact` (Pest)
    - `vendor/bin/pint --dirty --format agent` after touching PHP
    - `vendor/bin/phpstan analyse --no-progress`
    - `bun run build:ssr` when SSR-relevant files change
- **Run the narrowest tests that cover the change** while working; run the full suite before finishing.
- **Commits:** conventional commits only (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`). No AI attribution lines. Commit or push only when asked.
- **Documentation files** are created only when requested. Hard rules go in this `docs/` directory.
- **Dependencies** are not added or changed without approval. Tailwind stays on v4 and Vite+ stays on the pinned version in `package.json`.
