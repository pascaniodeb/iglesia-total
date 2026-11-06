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

## Layer map (what each piece is, and where the code lives today)

| Hexagonal role               | Piece                                                                     | Today's location (flat layout)              |
| ---------------------------- | ------------------------------------------------------------------------- | ------------------------------------------- |
| Driving adapter (HTTP)       | Controller: transport only (auth, validation, status codes)               | `app/Http/Controllers/MemberController.php` |
| Driving adapter (input)      | `FormRequest`: validation and authorization at the boundary               | `app/Http/Requests/MemberRequest.php`       |
| Driving adapter (output)     | API/Inertia `Resource`: the shape sent to the UI                          | `app/Http/Resources/MemberResource.php`     |
| Read model                   | `Listing` + `TableQuery`: sort/search/filter whitelists and the row shape | `app/Http/Tables/`                          |
| Application (use case)       | Invokable `Action`: one business operation, one transaction               | `app/Actions/Members/*`                     |
| Domain                       | Enums with behavior (`label()`), rules that do not need the framework     | `app/Enums/*`                               |
| Driven adapter (persistence) | Eloquent model, migration                                                 | `app/Models/*`, `database/migrations`       |
| Frontend                     | Feature folder: columns, forms, dialogs of one module                     | `resources/js/features/<module>/`           |

## Status (be honest about it)

- The code written so far (Members, Users/Auth) lives in the **flat Laravel layout** above. It already follows the roles in the table: controllers are thin, business rules live in invokable Actions (`SaveMember`, `LinkSpouse`, `DeleteMember`, `RestoreMember`), and listings are declared in a `Listing` class.
- It has **not** been moved into per-module folders yet and Actions still use Eloquent models directly (no repository ports). That is acceptable until a module needs a second adapter or its rules need to run without the framework.

## Proposed module layout (not yet agreed: confirm before moving code)

```text
app/Modules/<Module>/
├── Domain/          # enums, value objects, pure rules, port interfaces
├── Application/     # use cases (invokable Actions), DTOs
├── Infrastructure/  # Eloquent models, repositories, migrations helpers
└── Http/            # controllers, requests, resources, listings
app/Modules/Shared/  # TableQuery, HasOptions, other business-free kernel
resources/js/features/<module>/   # UI of the module
```

When this is agreed, move one module at a time (Members first), keep the namespace change in its own commit, and update this file.

## Adding a module

1. Write the use cases first as Actions with tests (a test per business decision), then the adapters.
2. Expose to other modules only what they need, as an interface or DTO.
3. Add its routes under a prefix with `can:<module>.view` / `can:<module>.manage` middleware; fixed paths before `{model}`.
4. Build the UI from the kit in [components.md](components.md); do not fork the table or form components.
