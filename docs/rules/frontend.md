# Frontend

## SSR

- SSR is on. The entry is `resources/js/ssr.tsx`; the Inertia Vite plugin wraps its `createInertiaApp` call with the server bootstrap, so keep that call literal.
- Anything shared by the browser and SSR entries (layout resolution, title, providers) lives in `resources/js/lib/inertia-app.tsx`. Never duplicate it in `app.tsx` and `ssr.tsx`.
- Code that runs during render must be safe on the server. Guard browser APIs (`window`, `document`, `localStorage`, `matchMedia`) with `typeof window !== 'undefined'` or use them only inside effects/event handlers. For components that cannot render on the server use `WhenMounted` from `@inertiajs/react`.
- Hooks that read the browser use `useSyncExternalStore` with a server snapshot (see `use-appearance`, `use-mobile`).
- Tests disable SSR (`Inertia::disableSsr()` in `tests/TestCase.php`).
- Production: `bun run build:ssr`, then `php artisan inertia:start-ssr` under a process monitor; `php artisan inertia:stop-ssr --graceful` on deploy.
- A change that touches SSR-relevant code is verified by requesting a page with the dev servers running and checking for `data-server-rendered="true"` in the HTML.

## TypeScript

`tsconfig.json` is strict and must stay that way: `strict`, `noUncheckedIndexedAccess`, `noImplicitOverride`, `noImplicitReturns`, `noFallthroughCasesInSwitch`, `verbatimModuleSyntax`.

- No `any`. Use `unknown` and narrow.
- Type-only imports use `import type`.
- Indexed access (`arr[i]`, `record[key]`) is handled as possibly `undefined`.
- Shared Inertia props are typed in `resources/js/types/global.d.ts`; page props via `usePage<...>()`.

## Design system

Ported from the SAFIPUV v4 visual system (visual only, no business logic).

- Colors come from semantic tokens in `resources/css/app.css` (`background`, `foreground`, `primary`, `border`, `positive`, `negative`, `success`, `warning`, brand scale `brand-50…900`). No hardcoded hex or neutral Tailwind palette colors in components.
- Light and dark themes are both required for any new UI.
- Fonts: Inter Variable (sans) and JetBrains Mono Variable (mono). Use the fluid type utilities (`text-hero`, `text-page`, `text-display`, `text-title`, `heading-*`, `eyebrow`), not ad-hoc font sizes.
- Control sizing comes from `--control-height` and related variables; dialogs and sheets switch to compact sizes automatically. Do not override heights per page.
- Forms use the `components/form/*` kit (`field`, `text-field`, `form-section`, `form-actions`, ...). Page chrome uses `page-header`, `empty-state`, `status-badge`.
- Toasts: the server sends flash `toast` with `type` (`success` | `info` | `warning` | `error`) and `message`; `title` and `duration` are optional.
- Navigation links use Inertia `<Link>` with `prefetch` for primary navigation.
- UI copy is English. No domain or house-style copy (e.g. forced uppercase) copied from other projects.
