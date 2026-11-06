# Reusable components and recipes

The building blocks every module reuses. Do not copy them into a module; extend them if something is missing (and update this file in the same change). Architecture rules are in [architecture.md](architecture.md); layout rules in [frontend.md](frontend.md).

## Backend kit

| Piece             | Where                                                    | Use it for                                                                                                                                                                                                              |
| ----------------- | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TableQuery`      | `app/Modules/Shared/Tables/TableQuery.php`               | Server-side search, status (soft delete aware), facets, sort and offset pagination with total. Built from the request through `fromRequest()` with whitelists.                                                          |
| `<Module>Listing` | e.g. `app/Modules/Members/Http/Tables/MemberListing.php` | One class per listing: `sortable()` (public key → qualified column), `searchable()` (accent-insensitive columns or closures), `facets()` (validators), `base()` (filter-only query), `row()` (shape sent to the table). |
| `HasOptions`      | `app/Modules/Shared/Concerns/HasOptions.php`             | Backed enum with `label()` → `options()` list `[{value, label}]` for selects and facets.                                                                                                                                |
| Invokable Actions | `app/Modules/<Module>/Application/Actions/`              | A business operation in one transaction (`SaveMember`, `DeleteMember`, `RestoreMember`, `LinkSpouse`). Throw `ValidationException` for business rejections; controllers only call them.                                 |
| `FormRequest`     | `app/Modules/<Module>/Http/Requests/`                    | Validation + `authorize()` through a permission. Field names get a Spanish entry in `lang/es/validation.php` `attributes`.                                                                                              |
| `Resource`        | `app/Modules/<Module>/Http/Resources/`                   | Detail payload for the edit/show page.                                                                                                                                                                                  |
| Toasts            | controllers                                              | `Inertia::flash('toast', ['type', 'message'])` with a `__('English key')` message translated in `lang/es.json`.                                                                                                         |

### Recipe: a new listing (backend)

1. Create `<Module>Listing` with `sortable`, `searchable`, `facets`, `base`, `row`.
2. In the controller `index()`:

    ```php
    $table = TableQuery::fromRequest($request, Listing::sortable(), Listing::DEFAULT_SORT, Listing::facets(), defaultStatus: 'active');
    $rows = $table->paginate(Listing::base($table), Listing::searchable())->through(Listing::row(...));
    return Inertia::render('<module>/index', ['rows' => $rows, 'filters' => $table->filters(), ...enum options]);
    ```

3. Keep the count query free of joins; add joins/counts for presentation only through `paginate(..., decorate: fn)`.
4. Add the search column (generated `search_*` with trigram index) in the migration when the search is by name.
5. Test: sorting, each facet, search with and without accents, status, per-page, tampered URL values.

## Frontend kit

| Piece             | Where                                              | Use it for                                                                                                                                                                                                                                                                           |
| ----------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ServerDataTable` | `components/data-table/server-data-table.tsx`      | The whole listing: search, facet chips, filters sheet, sort, pagination, loading/empty states, table on wide containers and cards on narrow ones. A page only declares columns, facets and actions.                                                                                  |
| `DataTableColumn` | `components/data-table/types.ts`                   | Column: `key`, `header`, `cell`, `sortKey?`, `mobile` role (`primary`/`badge`/`detail`/`hidden`), `priority` (`secondary`/`tertiary` appear as the table gets room).                                                                                                                 |
| `useTableQuery`   | `hooks/use-table-query.ts`                         | Listing state in the URL (search, status, facets, sort, page). Strips defaults, resets to page 1, partial reloads.                                                                                                                                                                   |
| Table types       | `types/table.ts`                                   | `Paginated<T>`, `TableFilters` mirror the PHP side.                                                                                                                                                                                                                                  |
| Row actions       | `components/data-table/data-table-row-actions.tsx` | Row menu (non-modal so the dialog it opens gets focus). Pair it with a confirm dialog (see `member-trash-dialog.tsx`).                                                                                                                                                               |
| Form kit          | `components/form/*`                                | `Field` (label + error + description, accessible ids), `TextField`, `TextareaField`, `SelectField` (`''` = nothing chosen, optional clear option), `DateField`, `CheckboxField`, `FormSection` + `Cell` (container-query grid: `third`, `half`, `twoThirds`, `full`), `FormActions`. |
| `PageHeader`      | `components/page-header.tsx`                       | Page title block; `compact` for form pages that need the height.                                                                                                                                                                                                                     |
| `usePermissions`  | `hooks/use-permissions.ts`                         | `can('members.manage')` to show/hide controls. Courtesy only: the server enforces.                                                                                                                                                                                                   |
| Date helpers      | `lib/date.ts`                                      | ISO date handling for `<input type="date">`.                                                                                                                                                                                                                                         |
| Status badge      | `components/status-badge.tsx`                      | Coloured state pill; build `<module>-status-badge.tsx` on top of it.                                                                                                                                                                                                                 |

### Recipe: a new listing (frontend)

1. `pages/<module>/index.tsx`: `<PageHeader …/>` + `<ServerDataTable url={index.url()} prop="rows" data={rows} filters={filters} columns={columns} facets={…} rowKey={…} rowActions={…} actions={…} searchLabel=… empty={…} statusLabel=… />`.
2. `features/<module>/components/<module>-columns.tsx`: the `DataTableColumn[]`; mark one `primary`, status as `badge`, the rest `detail`, extras `secondary`/`tertiary`.
3. Routes come from Wayfinder (`@/routes/<module>`), never literal URLs.
4. Run the layout pass (see frontend.md): no horizontal scroll, page does not scroll from 768 up.

### Recipe: a form by sections (create + edit + read-only)

The Members form is the reference (`features/members/components/member-form*.tsx`).

- One `useForm<Data>` for the whole card; `initialData(entity?)` turns `null` into `''`.
- A `STEPS` array: `{ label, fields, Component }`. A section knows its fields so `onError` can jump to the first section with errors and the nav can show an error dot (`sectionHasErrors` also matches `children.0.name` style keys).
- Layout contract: root `<form data-fit-viewport>`, card `<section data-fit-scroll>`, scrolling area `<div data-fit-body className="min-w-0 …">`, fields inside `<fieldset disabled={readOnly} className="m-0 min-w-0 border-0 p-0">`. Actions stay outside the scrolling area.
- Section nav: equal-width grid columns (never overflows); names appear only when the container is wide (`@container`).
- Footer and button groups use container queries, not viewport breakpoints.
- Fields go in `FormSection`/`Cell` grids so one column on phones and up to 6 on wide containers.
- Submit with `form.submit(entity ? update(entity.id) : store(), { onError })` using Wayfinder routes.
- Repeaters (children, roles) live in their own components and replace the list as a whole on save; the server action must delete-and-recreate inside the transaction.
- Edit page: breadcrumbs through `setLayoutProps`, not a function-style layout. Read-only mode (trashed record) passes `readOnly` and a `notice`.
- Sections must fit a 1366×768 laptop; a section that grows with data may scroll inside the card, never the page.

## What not to do

- Do not build a second table, filter bar or form field for one module's convenience.
- Do not paginate or filter in the browser.
- Do not compare roles in code; ask for a permission.
- Do not put business rules in controllers or React components; they go in an Action and are tested there.
