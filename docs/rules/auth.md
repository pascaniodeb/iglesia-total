# Authentication, users and roles

## Model

- **A user is a member.** `users.member_id` links the account to its person in the registry (`members`), unique (one account per member) and `RESTRICT` on delete. It is nullable only while accounts that predate the link exist; new accounts must be created for a member.
- `users.id` is UUIDv7 like every table. The only exception is `passkeys.id`, which belongs to the Fortify package; its `user_id` is a UUID foreign key.
- `role`, `is_active` and `member_id` are **never** mass assignable and are never read from request input. Only trusted code sets them explicitly.

## Roles

`App\Enums\UserRole`. A role is the office the member holds in the system.

| Role       | Value        | In short                                                                           |
| ---------- | ------------ | ---------------------------------------------------------------------------------- |
| Pastor     | `pastor`     | Super administrator: holds every permission and bypasses the Gate.                 |
| Asistente  | `asistente`  | Assistant of the pastor: dashboard and read-only member registry.                  |
| Secretario | `secretario` | People: members, member reports, church data, inventory.                           |
| Tesorero   | `tesorero`   | Money: offerings, closures, accounting; reads catalogs.                            |
| Contralor  | `contralor`  | Oversight: read-only over money and members, plus audits and the sealed ledger.    |
| Miembro    | `miembro`    | Portal-only account of a regular member: no module access, only their own profile. |

The values of the old system (`secretaria`, `tesoreria`, `contraloria`) were renamed to the title of the office holder; `users:import-legacy` maps them (`UserRole::fromLegacy()`).

## Permissions

- **Ask for permissions, never for roles.** Routes, controllers, policies and the frontend check a permission (`App\Enums\Permission`, e.g. `members.view`). The role-to-permission map exists in exactly one place: `UserRole::permissions()`. Never write `$user->role === ...` or `role === '...'` to decide access.
- Every permission is a Gate ability with the same name: `Route::middleware('can:members.view')`, `$user->can('members.view')`, `usePermissions().can('members.view')` in the frontend (the server shares `auth.permissions`). Hiding something in the interface is a courtesy; the server is what refuses.
- A new module declares its permissions in `Permission`, assigns them in `UserRole::permissions()`, protects its routes with `can:` and updates the TypeScript `Permission` type (a test fails if the PHP and TypeScript lists differ).
- Convention `<module>.view` / `<module>.manage`. Whoever can manage a module can also view it, and no permission may be left without an owner (both enforced by tests).
- The pastor is the super administrator (`Gate::before`). A deactivated account is denied everything, whatever its role.
- Accounts without dashboard access (members) are redirected to their own profile, which is also where login sends them.

### Matrix

Generated from the enums; the explicit table in `tests/Unit/Enums/UserRoleTest.php` is the specification.

| Permiso                  | Pastor | Asistente | Secretario | Tesorero | Contralor | Miembro |
| ------------------------ | :----: | :-------: | :--------: | :------: | :-------: | :-----: |
| `dashboard.view`         |   ✓    |     ✓     |     ✓      |    ✓     |     ✓     |         |
| `members.view`           |   ✓    |     ✓     |     ✓      |          |     ✓     |         |
| `members.manage`         |   ✓    |           |     ✓      |          |           |         |
| `member_reports.view`    |   ✓    |           |     ✓      |          |     ✓     |         |
| `church_settings.manage` |   ✓    |           |     ✓      |          |           |         |
| `assets.view`            |   ✓    |           |     ✓      |          |           |         |
| `assets.manage`          |   ✓    |           |     ✓      |          |           |         |
| `offerings.view`         |   ✓    |           |            |    ✓     |     ✓     |         |
| `offerings.manage`       |   ✓    |           |            |    ✓     |           |         |
| `closures.view`          |   ✓    |           |            |    ✓     |     ✓     |         |
| `closures.manage`        |   ✓    |           |            |    ✓     |           |         |
| `accounting.view`        |   ✓    |           |            |    ✓     |     ✓     |         |
| `accounting.manage`      |   ✓    |           |            |    ✓     |           |         |
| `catalogs.view`          |   ✓    |           |            |    ✓     |     ✓     |         |
| `catalogs.manage`        |   ✓    |           |            |          |           |         |
| `receipts.administer`    |   ✓    |           |            |          |           |         |
| `tithes.view`            |   ✓    |           |            |          |           |         |
| `tithes.manage`          |   ✓    |           |            |          |           |         |
| `sealed_ledger.view`     |   ✓    |           |            |          |     ✓     |         |
| `audits.view`            |   ✓    |           |            |          |     ✓     |         |
| `audits.record`          |   ✓    |           |            |          |     ✓     |         |
| `activity_log.view`      |   ✓    |           |            |          |           |         |
| `users.manage`           |   ✓    |           |            |          |           |         |

Where this improves the old system: its checks were scattered `CAN_VIEW` sets, one per file, and they disagreed with the menu. Tesorería could _modify_ tithes without being able to _see_ them; offering categories and account codes sat under the pastor-only Ajustes menu while the server also accepted Tesorería. Now tithes are pastor-only, and catalogs are viewed by Tesorería and Contraloría but managed only by the pastor.

## Changing roles

- Only through `App\Actions\Users\ChangeUserRole` (the users screen will call it with the acting user; the `users:set-role {email} {role}` command calls it as the operator).
- Safeguards: the actor needs `users.manage`; nobody changes their own role; there must always be at least one **active** pastor; a `miembro` account needs its linked member.

## Sessions and passwords

- A deactivated account (`is_active = false`) cannot use the app, however it authenticated (password, passkey, or a session opened earlier). `EnsureUserIsActive` signs it out on the next request and flashes an error toast.
- Passwords are never copied from the old system (its hashes are better-auth scrypt, which PHP cannot verify natively). Imported accounts get an unusable random password. Set a real one with `php artisan users:set-password {email}` (hidden prompt, never an argument), with `--generate` (creates a strong random password and shows it once, no typing needed) or the password reset flow. A password was set if the account's `updated_at` moved after the import.
- The mail driver is `log` locally: password reset links appear in `storage/logs/laravel.log`.

## Legacy import

- Run `members:import-legacy` first, then `users:import-legacy` (`--dry-run` to preview). It refreshes name, email, role, active flag and member link, but never passwords. It fails before writing anything if a legacy role cannot be mapped. Run it only until the cut-over: afterwards it would overwrite changes made here.

## Open decisions

- **Asistente** has no counterpart in the old system. It starts with the least a pastor's assistant needs (dashboard and read-only members); widening it means editing `UserRole::permissions()` and the specification table.
- **Miembro** is kept as the portal-only role (it existed in the old system and is the least-privilege default for new accounts), although it is not one of the staff roles.
- Public registration (`Features::registration()`) is still enabled, as in the starter kit. New accounts get the `miembro` role, but the church system is meant to create accounts for members only.
