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
- No domain or house-style copy (e.g. forced uppercase) copied from other projects.

## Layout: no scrolling surprises

- **Never horizontal scroll**, on any viewport. Every flex/grid child that holds text or inputs gets `min-w-0`; wide content wraps or becomes cards (lists switch from table to cards by container width, not screen width).
- **From 48rem up the page itself never scrolls vertically.** Pages that fill the screen use the `data-fit-viewport` / `data-fit-scroll` / `data-fit-body` contract in `app.css`: the page is exactly viewport height and only the body inside the card scrolls. On phones the natural page scroll is kept.
- Forms with many fields are split into sections (steps) sized to fit the card on a laptop (1366×768). A section whose content grows with data (children, roles) may scroll inside the card, never the page.
- Footers and button groups use container queries (`@container`, `@2xl:`), not `sm:` breakpoints, because the sidebar changes the real available width.
- Verify with a headless-browser pass at 360, 390, 768, 1366, 1440 and 1920 wide: `scrollWidth <= innerWidth` and `scrollHeight <= innerHeight` from 768 up.

## Data tables

- Lists use the `components/data-table/*` kit (`ServerDataTable`) with `useTableQuery`: search, status, facets, sort and page live in the URL and the server does the work (`App\Http\Tables\TableQuery`). Pages only declare columns and facets.
- Inertia 3: set breadcrumbs with `setLayoutProps({ breadcrumbs })` or a static `Page.layout = { breadcrumbs }`; function-style layouts that read page props crash.

## Language

- The whole application is in **neutral Spanish** (users are in Venezuela), addressing the user as **"usted"** (never voseo or "tú"). `APP_LOCALE` and `APP_FALLBACK_LOCALE` are `es`; faker is `es_VE`.
- Code, identifiers and comments stay in English. Only user-facing copy is Spanish.
- Frontend copy is written directly in Spanish in the components (single-language app, no i18n library). Reuse the glossary: "Ajustes" (settings), "Panel" (dashboard), "Llave de acceso" (passkey), "Correo electrónico" (email), "Contraseña" (password), "Iniciar / Cerrar sesión" (log in / out).
- Backend messages use the Spanish files in `lang/es/` (`auth`, `passwords`, `validation`, `pagination`). Strings written in PHP use `__('English key')` and their translation goes in `lang/es.json`; a missing key silently shows English, so add the translation in the same change. New form fields need an entry in `validation.attributes` so errors read "El campo correo electrónico es obligatorio".
- Toasts (`Inertia::flash('toast', ['type', 'message'])`) carry the Spanish `message`; they appear top-right.
