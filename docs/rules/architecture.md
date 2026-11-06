# Architecture

## Direction

- The application is a **modular monolith**: one deployable, one database, one codebase split into business modules (Members, Treasury, Auth/Users, ...).
- Inside each module the design is **hexagonal** (ports and adapters): the domain and the use cases know nothing about Laravel, HTTP, Eloquent or Inertia; those are adapters around them.

## Dependency rules

- Dependencies point inward: `adapters (HTTP, persistence, UI) → application (use cases) → domain`. The domain imports nothing from the framework.
- A module never reaches into another module's internals (models, tables, actions). It talks to it through that module's public contract (an interface or a small DTO) or through events.
- Cross-module data is referenced by id only. No foreign-key joins in one module's queries onto another module's tables, except for read-only listing/reporting queries that are declared as such.
- Shared kernel: only generic, business-free code goes in `Shared` (table query, enum helpers, form/table UI kit). If it names a business concept, it belongs to a module.
- Permissions are checked with Gates (`members.view`), never by role. Roles are mapped to permissions in one place (`App\Enums\UserRole`).

## Module layout (agreed)

```text
app/Modules/<Module>/
├── Domain/Enums/            # enums with behavior, value objects, pure rules, port interfaces
├── Application/Actions/     # use cases: one invokable Action per business operation
├── Infrastructure/Models/   # Eloquent models (persistence adapter)
└── Http/
    ├── Controllers/         # transport only (auth, validation, status codes)
    ├── Requests/            # FormRequest: validation + authorize() at the boundary
    ├── Resources/           # the shape sent to the UI
    └── Tables/              # <Module>Listing: sort/search/filter whitelists and the row shape
app/Modules/Shared/          # business-free kernel
├── Tables/TableQuery.php
└── Concerns/HasOptions.php
resources/js/features/<module>/   # UI of the module (columns, forms, dialogs)
```

Factories (`database/factories`), migrations, console commands (`app/Console/Commands`), routes and the generic UI kit stay where Laravel and the tooling expect them. Models point to their factory with `#[UseFactory]` because the default factory-name guess does not know about modules.

## Status (be honest about it)

- **Members** is the first module in this layout (`app/Modules/Members`).
- **Integrity** (`app/Modules/Integrity`) owns the activity log. Every module records its facts only through the `App\Modules\Integrity\Application\ActivityLog` contract, inside the same transaction as the write. It also owns the sealed ledger: a module that closes periods implements the `SealableSource` port (`App\Modules\Integrity\Domain\Ledger`) and registers it in `SealableSources` from its own provider; it seals through the `SealPeriod` action. Integrity never reads another module's tables. It is the first module with its own service provider (`IntegrityServiceProvider`, listed in `bootstrap/providers.php`).
- **Members** exposes the `App\Modules\Members\Application\MemberDirectory` contract (find, findMany, search, idsMatching); other modules keep only a member's id and read names through it. Its provider is `MembersServiceProvider`.
- **Treasury** (`app/Modules/Treasury`) owns offering categories, exchange rates and offerings; it reads accounts through Accounting's `ChartOfAccounts` and members through `MemberDirectory`.
- **Accounting** (`app/Modules/Accounting`) owns the chart of accounts and the double-entry journal. Other modules write to the books only through the `App\Modules\Accounting\Application\Ledger` contract (post a `JournalEntryDraft`, reverse by entry id), inside their own transaction and after checking their own permission.
- **Not modularized yet**: Users/Auth/Permissions (`app/Models/User.php`, `app/Actions/Users`, `app/Enums/Permission|UserRole`, Fortify) and the settings pages. They will become an Auth/Users module when it is worked on.
- **Known couplings to remove later**: `User` has a `member()` relation and `Member` a `user()` relation (users are members, see [auth.md](auth.md)); `DeleteMember` checks `$member->user()`. When Auth/Users becomes a module, replace these with a contract owned by one side.
- Actions still use Eloquent models directly (no repository ports) and `label()` on enums uses the translation helper. That is acceptable until a module needs a second adapter or its rules must run without the framework.

## Adding a module

1. Write the use cases first as Actions with tests (a test per business decision), then the adapters.
2. Expose to other modules only what they need, as an interface or DTO.
3. Add its routes under a prefix with `can:<module>.view` / `can:<module>.manage` middleware; fixed paths before `{model}`.
4. Build the UI from the kit in [components.md](components.md); do not fork the table or form components.
