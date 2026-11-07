# Roadmap: Treasury, Accounting and the sealed ledger

Migration of the old system's money logic (TanStack/Drizzle, `/Projects/TanStackProjects/iglesiatotal`) into this modular monolith, following the patterns proven in safipuv-v4 (`Accounting`, `Treasury`, `Organization/Audit` modules). Status: **plan approved 2026-11-06; A-F1, A-F2, B-F1, B-F2, B-F3, B-F4 (screens), C-F1, C-F1b, C-F2, C-F3 (core) and C-F3b done**. All decisions (D1–D7) are settled; see section 6.

## 1. What exists in the old system

### Data (legacy DB, counted 2026-11-06)

| Table                                  |   Rows | Notes                                                                                    |
| -------------------------------------- | -----: | ---------------------------------------------------------------------------------------- |
| `account_codes`                        |     26 | I-001…I-009 (I-001 = saldo anterior), E-021…E-037. Single chart, `income`/`expense`.     |
| `offering_categories`                  |      3 | Each one points to an account code (NOT NULL on purpose).                                |
| `offerings`                            |    781 | 2025-01-05 → 2026-11-01, 6 soft-deleted, all with a member. Amounts in VES, USD and COP. |
| `weekly_closures`                      |     96 | All `closed`; 82 with a stored PDF, 0 with the photo of the paper book.                  |
| `monthly_closures`                     |     22 | 13 `closed`, 9 `draft`.                                                                  |
| `accounting_entries`                   |    183 | Single-entry (type + code + 3 amounts). 78 posted, 25 soft-deleted, 0 with support file. |
| `ledger_blocks`                        |    110 | 1 genesis + 96 weekly (2025-W01→2026-W44) + 13 monthly (2025-10→2026-10). 0 anchored.    |
| `audits` / `audit_acknowledgements`    | 2 / 22 | Contraloría audit runs and accepted findings (by fingerprint).                           |
| `activity_log`                         |    203 | Append-only by trigger; actor name/role snapshots.                                       |
| `tithes`, `receipts`, `exchange_rates` |      0 | Built but never used in production. `receipt_settings.start_number = 1`.                 |

### Business rules worth keeping

- **Money in three currencies** (VES, USD, COP), never converted to one another in the books. Amounts are `numeric(18,2)` and travel as strings (never floats).
- **Week and month keys are generated columns** (`YYYY-Www` ISO week, `YYYY-MM`), computed in one place only.
- **Weekly closure** totals the week's offerings; closing it seals the week. **Monthly closure** consolidates offerings by category into income entries (one per account code, dated at month end) and the month's accounting close carries the balance forward as an I-001 entry in the next month and locks (`posted_at`) the month's entries.
- **Tithes are not church income.** They go to the workers and never enter the books, closures or sealed ledger. Pastor-only. A tithe always has a member; an offering may be anonymous.
- **Receipts (comprobantes IPUV):** one gapless number series shared by income and expense, assigned under a table lock inside the insert transaction; a wrong receipt is voided (keeps its number, reason required), never deleted; one account code per receipt; printed on the IPUV paper format.
- **Sealed ledger:** hash-chained blocks (`genesis`, `weekly`, `monthly`). Each block stores `prev_hash`, a Merkle root of the sealed records, `payload_hash` = SHA-256 of a canonical JSON payload, and `hash` = SHA-256 of `height`, `prev_hash`, `merkle_root` and `payload_hash` joined by a NUL byte. Verification has two passes: **chain** (recompute every hash from genesis: tampering = security incident) and **drift** (rebuild each payload from the live books and compare: data changed after the close = closure to regenerate). Merkle proofs let an auditor check one offering without the whole week.
- **Contraloría audits:** 9 live checks (carry-forward chain, weekly/monthly closure totals, offering after close, stale PDF, week without closure, closure without PDF, month-straddling week, inventory location). An audit run stores a snapshot; an accepted finding is bound to its fingerprint, so if the data changes the finding comes back by itself.

### Weaknesses we fix while migrating

| Old behavior                                                            | New behavior                                                                               |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Entries editable/deletable until posted; lock enforced only in app code | Append-only tables guarded by PostgreSQL triggers; corrections are reversals with a reason |
| Single-entry books; balance arithmetic in JS `Number`                   | Double-entry with DB-enforced balance per currency; exact decimal math (`brick/math`)      |
| I-001 written as an entry each month (can drift from the real close)    | Opening balance derived from the sealed balances of the previous closed month (D3)         |
| Closed weeks/months can be reopened, producing drift                    | Closed periods are never reopened; late facts go to the open period (D7)                   |
| Sealed ledger guarded by `REVOKE` only; no external anchor ever made    | Triggers + daily verification + head hash printed on the monthly PDF + external anchoring  |
| Sealed payloads reference integer ids                                   | Payloads reference UUIDv7 ids; the legacy chain is archived and verified as-is (see C-F3)  |

## 2. Target modules

```text
app/Modules/
├── Shared/        (extend) Money: Currency, Decimal, Money, Percentage (ported from safipuv-v4); Period: WeekKey, MonthKey; PDF letterhead
├── Integrity/     (new) activity log chain, sealed ledger (blocks + Merkle), verification, anchoring, Contraloría audits
├── Accounting/    (new) chart of accounts, double-entry journal, periods and closes, general ledger, reports
└── Treasury/      (new) offering categories, offerings, weekly closures, receipts, tithes, imports, dashboards
```

### Dependency rules between them

```text
Treasury ──(LedgerPort: post/reverse an entry draft)──────────▶ Accounting
Treasury ──(SealableSource: weekly payload builder)───────────▶ Integrity
Accounting ─(SealableSource: monthly payload builder)─────────▶ Integrity
Accounting ─(OfferingTotalsSource: port declared in Accounting, adapter in Treasury)─▶ Treasury
Treasury, Accounting ──(MemberDirectory: names by id)──────────▶ Members
everyone ──(AuditTrail: record an action)─────────────────────▶ Integrity
```

- Integrity never reads Treasury or Accounting tables: each module registers its payload builder in its service provider (`callAfterResolving`, safipuv pattern). Accounting never reads Treasury tables; Treasury never writes Accounting tables.
- Cross-module references are UUIDs (`member_id`, `account_id`). DB foreign keys stay for integrity; names are read through the owning module's contract, except in read-only listing/report queries declared as such.
- The domain layer of each module is pure PHP: `Canonicalizer`, `MerkleTree`, `BlockHash`, `ChainVerifier`, `JournalEntryDraft`, `MoneyBag`, `ReceiptNumber`. No Eloquent, no facades. These are unit-tested without the database.
- Each module gets a `<Module>ServiceProvider` (routes, commands, schedule, port bindings, registrations). Members has none yet; it gets one when it needs to expose `MemberDirectory`.

### Shared database guards (one migration, used by every module)

- `forbid_update()` and `forbid_physical_delete()` trigger functions (also on `TRUNCATE`).
- Append-only tables: `activity_log`, `ledger_blocks`, `legacy_ledger_blocks`, `journal_entries`, `journal_entry_lines`, `journal_entry_supports`, `accounting_periods`, `accounting_period_balances`, `audits`, `audit_acknowledgements` (revocation is a new row), `receipt_voids`.
- Honest limit (same as old and safipuv): the table owner can disable triggers. That is why the chain head is printed on paper and anchored outside the database.

## 3. Phases

Order matters: Integrity's core and Accounting's core come first because Treasury posts into one and seals into the other. Legacy imports run at the end, in one ordered command, so the target schema is final when data arrives.

### 3.1 The month circuit (D2, approved 2026-11-06)

The path money follows every month. It is one state machine, enforced on the server, and shown to the user as a four-step guide ("Circuito del mes") so that at any moment they can see where the month stands, what is missing and why a button is disabled.

```text
 1. Weeks                2. Offering closure         3. Journal                    4. Accounting close
 close each week   ──▶   all weeks touching the ──▶  register the other      ──▶   month finished, previous month
 (seal weekly block)     month closed → post         income and expenses           closed, checks green → snapshot
                         offering income by code     (manual, with support)        balances, seal monthly block,
                                                                                   next month opens with the
                                                                                   new opening balance
```

| Step | Who                            | Can start when                                                                                      | What it does (one transaction)                                                                                      | Afterwards                                               |
| ---- | ------------------------------ | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| 1    | Tesorero (`closures.manage`)   | The week has ended                                                                                  | Snapshots the week's totals, locks its offerings, seals the weekly block                                            | Offerings of that week can no longer change              |
| 2    | Tesorero (`closures.manage`)   | Every week with a day in the month is closed                                                        | Snapshots offerings by category, posts one income entry per code, checks posted = offerings to the cent             | Offering income of the month is in the journal           |
| 3    | Tesorero (`accounting.manage`) | The month is not closed and the entry date is inside it (1st to last day), never in the future      | Manual income/expense entries with support; corrections only by reversal with a reason                              | —                                                        |
| 4    | Tesorero (`accounting.manage`) | Month finished, step 2 done, previous month closed, every `ledger:verify` check for the month green | Snapshots balances per account and currency, seals the monthly block, opens the next month with its opening balance | The month is read-only forever; the next month is active |

Rules that make it safe:

- **Server is the referee.** Every precondition is re-checked inside the transaction, under a lock on the month (`SELECT … FOR UPDATE` on the period row), so a double click, two tabs or two users cannot close or post twice. Each step is idempotent by its origin key.
- **All or nothing.** If any check fails inside a step, nothing is written. Sealing happens right after the commit; if it fails the step stays done and the month shows "seal pending" with a retry.
- **Nothing is reopened** (D7). A mistake found later is corrected in the active month with a reversal that names the original.
- **Each month owns its movements** (approved 2026-11-06). An entry belongs to the month of its date (e.g. 1–31 October) and can only be recorded while that month is open; the journal, the reports and the close of a month only ever contain that month's dates.
- **At most two open months.** The month after the last closed one and the following one. Example: October 2026 ends on a Saturday but its last week closes on Sunday 1 November; meanwhile November can record its own entries (dated 1–30 November) without touching October. November's opening balance is shown as **provisional** until October's accounting close fixes it; November cannot be closed before October. If both are open, December waits.
- **No overdraft, ever** (D4, approved 2026-11-06): an expense larger than the available cash in its currency is rejected before it is written, with a message that says how much there is and how much is missing. The check runs on the server under a lock per currency, and also for back-dated expenses (the balance must never go below zero from that date to today). A reversal of an income whose money was already spent is rejected the same way.
- **Available cash = opening balance + offerings of the month's closed weeks + other income − expenses, per currency** (approved 2026-11-06). Offerings reach the journal only at the monthly offering closure (D2), but the money of a closed week is already counted, locked and sealed, so it counts as available. Offerings of a week still open do not count. Measured on the legacy data: 13 months dip below zero mid-month only because the old system consolidated offerings at month end; without this rule D4 would block legitimate expenses.
- **Available cash with an open previous month** is computed live as the previous month's running balance plus the current month's movements, under the same lock, so a provisional opening balance can never be used to overspend.
- **Offerings never wait for accounting.** Weekly offering registration and weekly closes keep working while the previous month's accounting is still being closed.
- **Every step is recorded** in the activity log (who, when, totals) and has its PDF (weekly closure, monthly offering closure, monthly accounting report with the chain head hash).

Rules that make it understandable:

- A disabled step always says why, in plain words and with the exact item: "Falta cerrar la semana del 26 de octubre al 1 de noviembre", "Octubre todavía no terminó", "El cierre de ofrendas de octubre no está hecho".
- Before confirming steps 2 and 4 the user sees a preview with the figures that will be written: totals by code and currency, and for step 4 opening + income − expense = closing per currency, and the opening balance the next month will receive.
- Confirming step 4 asks for an explicit confirmation that states it is irreversible.
- After each step the screen shows the result and the next step, never a bare success toast.

### Module A — Integrity (Centro de integridad)

**A-F1 Activity log chain.** ✅ Done 2026-11-06 (also: occurred_at is stamped by the trigger with the real clock; account self-deletion removed, accounts are deactivated; legacy import of the old log is left for the cut-over). `activity_log` table: `seq`, `previous_hash`, `hash` computed by a `BEFORE INSERT` trigger under `pg_advisory_xact_lock` (safipuv `audit_logs_chain`), actor id + name/role snapshots, action, subject, before/after/diff (jsonb), request id, ip, user agent. Append-only. `AuditTrail` service used by every Action. `activity:verify` command. Screen for `activity_log.view` (server table, filters by actor/action/date).

**A-F2 Sealed ledger core (pure domain).** ✅ Done 2026-11-06 (the old chain's 110 blocks verify intact with the port; the block hash fields are joined by a NUL byte; PostgreSQL recomputes each block's link and hash on insert; `ledger:genesis` takes the head of the chain it continues). Port the old primitives to PHP: canonical JSON (sorted keys, amounts as strings, integers only for counts, no floats), SHA-256, Merkle root and proof (odd level duplicates the last leaf), block hash, two-pass verifier. **Golden tests:** fixtures produced by the old TypeScript code (a payload, its canonical string, leaves, root, proof, block hash) must give byte-identical results in PHP. `ledger_blocks` table (UUIDv7, `height` unique and gapless, `kind`, `period_key`, `prev_hash`, `merkle_root`, `payload_hash`, `hash`, `payload` jsonb, `sealed_at`, `sealed_by`; CHECKs on hash length; unique `(kind, period_key)`), append-only. `SealableSource` port + registry; `SealPeriod` and `VerifyChain` Actions; `ledger:verify` scheduled daily.

**A-F3 Legacy chain archive.** Import the 110 old blocks verbatim into `legacy_ledger_blocks` (append-only). Verify pass 1 (chain) with the A-F2 code: it must report the legacy chain intact, which also proves the port is exact. The new chain's genesis payload cites `legacy_head_hash`, `legacy_height` and the import date: custody continues from the old chain to the new one. Pass 2 for legacy periods is a reconciliation report: totals of each legacy sealed payload vs the same period rebuilt from the imported data.

**A-F4 Integrity center screen** (`sealed_ledger.view`). Chain status (intact / matches books), block list with drill-down to the sealed payload, unsealed closed periods with "seal pending", Merkle proof of a single offering, verification history, PDF certificate with the head hash. Integrity checks registry (safipuv `IntegrityChecks`) so each module adds its own check (ledger balance, closure totals).

**A-F5 Contraloría audits** (`audits.view`, `audits.record`). Port the checks that still make sense once data is immutable (carry-forward, closure totals, week without closure, stale PDF, closure without PDF, month-straddling week); drop "offering after close" (now impossible by construction, kept as a chain/drift finding). Audit runs with snapshot and PDF; acknowledgements by fingerprint with mandatory reason; revocation as a new row. Import the 2 audits and 22 acknowledgements (fingerprints recomputed where the subject id changed; unmatched ones listed in the import report).

**A-F6 External anchoring and row seals.** Anchor the chain head (sealed ledger and activity log) with RFC 3161 and/or OpenTimestamps (safipuv `Anchoring`), with offline verification. Optional HMAC row seals for Accounting/Treasury models (safipuv `Sealed`/`SealedModels`), so a direct SQL change outside Actions is detected by `ledger:verify`.

### Module B — Accounting (Contabilidad)

**B-F1 Chart of accounts.** ✅ Done 2026-11-06 (system accounts A-001 CAJA, A-002 PUENTE CAMBIARIO, I-001 SALDO ANTERIOR as equity; `accounting:import-legacy-accounts` brings the 26 legacy codes; code, type and function are immutable in the database; the `ChartOfAccounts` contract for Treasury is added with C-F1, when it has a caller). `accounting_accounts` (code unique, name, type income/expense, `system_function` for CAJA and opening balance, active flag). System cash accounts per currency. Catalog screen: managed by the pastor (`catalogs.manage`), read by Tesorería/Contraloría (`catalogs.view`). `ChartOfAccounts` contract for Treasury (resolve by id, list active income codes).

**B-F2 Double-entry core.** ✅ Done 2026-11-06 (lines name accounts by code; `Ledger` contract returns a `PostedEntry`; `journal:verify` recomputes the arithmetic nightly). (D1 approved) `journal_entries` (date, month, description, `origin_type`/`origin_id`/`origin_purpose` unique for idempotency, `reversal_of` + reason, `lines_count`, created_by) and `journal_entry_lines` (account, currency, debit/credit numeric(18,2), one side only). Deferred constraint triggers (safipuv): ≥ 2 lines, balanced per currency, a reversal mirrors its original exactly. Append-only. `LedgerPort` (post draft, post automatic, reverse) and `VerifyLedger` (global balance per currency, entry balance, period snapshots match lines). Income = debit CAJA / credit I-xxx; expense = debit E-xxx / credit CAJA; one legacy multi-currency entry becomes one entry with lines per currency.

**B-F3 Journal (Libro Diario) and monthly close.** ✅ B-F3a done 2026-11-07: accounting periods, `ClosePeriod` (step 4) with the balance snapshot checked by PostgreSQL, entries refused in closed months, the monthly block sealed with the cash statement (opening + income − expense + capital + exchange = closing) and one leaf per journal line; Treasury answers the `OfferingClosures` port. B-F3b done 2026-11-07: journal screen with income, expense and currency exchange by hand, void and correct by reversal; D4 computed as CAJA movements without the monthly offering entries plus closed-week offerings on their own dates, never below zero from the movement's date to today, checked after the write under a per-currency lock (a correction is judged on its result). B-F3c done 2026-11-07: supports per entry (`journal_entry_supports`, append-only, one per entry): receipt number and/or a photo or PDF in private storage; photos re-encoded to WebP (EXIF orientation applied, metadata dropped), PDFs kept as they are; SHA-256 of the stored bytes checked on every opening (409 if altered) and every opening recorded as an access; served through an `accounting.view` route instead of a signed URL; a correction carries the original's support; the monthly seal's leaves carry the receipt number and the file fingerprint. B-F3 done. Screen grouped by code per month, as the IPUV paper prints it: opening balance, income, expense, closing balance, per currency. Register manual income/expense (`accounting.manage`) with optional support file (private storage, SHA-256, signed URL, `journal_entry_supports` append-only). Edit = reversal + corrected entry in one transaction; void = reversal; reason mandatory. Overdraft rule (D4, section 3.1). **Currency exchange** movement: one entry that takes cash out in one currency and puts it in another (e.g. sell USD for COP) with the amounts actually exchanged; without it a strict per-currency rule would force fake income/expense entries. `accounting_periods` + `accounting_period_balances` (immutable snapshot per account and currency); `ClosePeriod` is step 4 of the month circuit (section 3.1): only for finished months, in order, after the monthly offering closure; it activates the next month with the closing balance as its opening balance and seals the month into Integrity (monthly payload: opening/income/expense/closing, by code, offering totals from `OfferingTotalsSource`, Merkle leaves = entry lines with receipt hash when present).

**B-F4 General ledger and reports** (`accounting.view`). ✅ Done 2026-11-07 (screens; the PDFs move to D-F2, which brings the shared letterhead): `Shared\Time\ReportPeriod` (month, quarter, semester, year, range of whole months; the old system's URL keys); `CashStatement` computes the statement between two days and `MonthSummary` is that statement for one month (same shape, the monthly seal unchanged); `PeriodReport` (one opening and one closing for the whole span, net per code, draft while a month is open); `GeneralLedger` (one account, normal-side amounts, balance before, running balance per currency computed by PostgreSQL, balance after; an income code's movement equals its row in the period report). Ledger per account, period summary (month, quarter, semester, year, range), PDFs with letterhead, verification code and the chain head hash; "BORRADOR" watermark when a month in the range is open. Opening balance never summed across months (old I-001 rule).

**B-F5 Legacy import.** Map the 26 codes; import the 158 live entries as journal entries (origin `legacy_entry`, `legacy_id`), skip the 25 soft-deleted ones (listed in the report). I-001 handling (D3): the first month's I-001 becomes the opening entry; later I-001 entries are not imported but compared with the derived opening balance (any difference stops the import). Close the 13 months closed in the old system, in order. History is imported as it is, negative balances included (measured: April 2026 closed with COP −51.300,00, carried as May's opening; 13 months dip below zero mid-month), flagged in the import report. D4 applies to every movement recorded after the cut-over.

**B-F6 Cent-exact reconciliation.** `reconcile:legacy` and the permanent `ledger:verify` checks described in section 4. It is the gate of the cut-over: nothing is considered migrated until every difference is `0.00`.

### Module C — Treasury (Tesorería)

**C-F1 Catalogs and settings.** ✅ Categories done 2026-11-06 (`ChartOfAccounts` contract; the database checks the account is income; `treasury:import-legacy-categories`). Receipt settings move to C-F4, where they have a caller. Offering categories (each points to an income account, NOT NULL), receipt settings (start number of the physical book). Exchange rates: see C-F1b (D5).

**C-F1b Exchange rates (exact port of safipuv-v4 `Treasury`).** ✅ Done 2026-11-06 (read with `catalogs.view`, sync and correct with `catalogs.manage`; the dashboard widget moves to C-F6 with the other widgets).

- Table `exchange_rates`: one row per Venezuelan date (`rate_date` unique). `usd_rate` numeric(14,4) Bs per USD (BCV), `cop_rate` numeric(16,6) COP per Bs, `eur_rate` numeric(14,4) Bs per EUR (BCV, nullable), `trm_cop_usd` numeric(14,4) COP per USD (TRM Colombia, nullable). `origin` is `market`, `manual` or `legacy`; a `market` row must have EUR and TRM. CHECK that every rate is positive. Rows are never deleted (trigger); each change is audited with its previous value.
- Domain (pure): `Rate` (exact, positive, with its scale) and `DailyRates`. `fromMarket` computes `cop_rate = TRM ÷ BCV` with 6 decimals, rounding HALF_UP. `toBolivares` gives a Bs equivalent with 2 decimals: USD is multiplied, COP is divided, VES stays the same.
- Port `MarketRateSource` with adapter `OfficialMarketRateSource`. BCV comes from DolarAPI (`promedio` for USD and EUR), TRM from datos.gov.co (latest `vigenciadesde` up to that date). Each value is read as decimal text and checked against a sane range (BCV 1–500.000, TRM 1.000–10.000). If any source fails, nothing changes (`RatesUnavailable`). URLs, timeout and ranges live in `config/treasury.php` and `.env`.
- Action `RecordExchangeRates`. `sync` calls the network outside the transaction, then writes under a lock on the day. A `manual` day is never overwritten by sync. `record` registers or corrects today or a past day by hand (never a future day), and from then on sync leaves that day alone.
- Command `treasury:sync-rates`, scheduled on weekdays, hourly from 07:00 to 20:00 `America/Caracas`, without overlapping. The screen "Tasas de cambio" shows the day's rates, the history, the origin badge, manual correction and a sync button, plus a dashboard widget.
- **Rule that keeps the books exact:** a rate only informs, compares and sorts. Money is posted, totalled, closed and sealed in its own currency, and `toBolivares` never feeds an entry, a closure or a balance. The currency exchange movement (B-F3) records the amounts actually exchanged; it can show the day's rate as a reference, but it does not compute amounts from it.
- Legacy: the old `exchange_rates` table is empty, so there is nothing to import.

**C-F2 Offerings.** ✅ Done 2026-11-06 (bulk import is Excel `.xlsx`, not CSV, approved by the owner, with OpenSpout; it is all or nothing: one rejected row and nothing is written; the closed-week lock arrives with C-F3). Registry screen (server table: week, month, category, member, amount; offset pagination, `search_name` on member via contract), create/edit/delete only while the week is not closed (delete = soft delete before close; after close nothing changes). Generated `week_key`/`month_key`. Anonymous offering allowed (`member_id` nullable). CSV bulk import with template, preview and a single transaction (old `offerings-import`).

**C-F3 Weekly closures.** ✅ Core done 2026-11-06 (weeks close in order with no gaps; the database recomputes the snapshot to the cent on insert and locks the week's offerings; `ledger:seal-pending` catches up seals; PDF and the photo of the paper book follow once a PDF library is approved). Generate (preview totals), close, PDF, photo of the paper book (private storage). Closing in one transaction: snapshot count and totals per currency, lock the week's offerings, then seal the weekly block. A weekly close does **not** post to the journal (D2). A sealing failure never undoes the close; it is reported and retried ("seal pending").

**C-F3b Monthly offering closure.** ✅ Done 2026-11-06 (one income entry per month with a credit line per income account and currency, instead of one entry per code; weeks before the first weekly closure are outside the circuit and not required, so the first month can close; months close in order; PostgreSQL checks the weeks, the snapshot, the CAJA debit and the category lines to the cent). Step 2 of the month circuit (section 3.1). Allowed only when every week with at least one day in the month is closed (including the weeks shared with the previous or next month). Takes the month's offerings **by offering date** (a shared week is split by date), snapshots count and totals per category and currency in `monthly_offering_closures`, and posts one income entry per account code to the journal through `LedgerPort` (debit CAJA / credit I-xxx, dated on the last day of the month, origin `monthly_offering_closure:{month}` so it can never be posted twice). Before committing it checks that the posted amounts equal the offerings to the cent; any difference rolls everything back.

**C-F4 Receipts (comprobantes).** Gapless shared series under lock, `start_number` respected, void with reason (`receipt_voids`, append-only), one account code per receipt, IPUV paper PDF (612×396 pt, Helvetica glyphs). Issuing a receipt posts its journal entry through `LedgerPort` and its hash enters the monthly Merkle leaf (as in old migration 0018). `receipts.administer` for settings and voids.

**C-F5 Tithes.** Book only: pastor-only (`tithes.view`/`tithes.manage`), member required, never posts to Accounting and never sealed. Registry, reports (monthly…annual + PDF), analysis (who stopped tithing), CSV import. Kept as a module rule with a test that fails if Tithes ever references `LedgerPort`.

**C-F6 Dashboards.** Offerings and tithes widgets (weekly trend per currency, by category, closures pending), honoring permissions.

**C-F7 Legacy import.** Categories → offerings (775 live, 6 soft-deleted skipped) → weekly closures (96, all closed, sealed in the new chain; posting happens in the monthly offering closures, D2) → receipt settings. Legacy PDFs are regenerated, not copied (D6). Totals per week must equal the legacy closure totals and the legacy sealed weekly payload (A-F3), or the import stops.

### Module D — Church identity and documents (added 2026-11-07)

The old `church_settings` table (one row: congregation name, pastor, code, RIF, denomination header, registration note, logos) was not in any phase; every official PDF needs it for its letterhead.

**D-F1 Church settings.** A single-row settings record (CHECK id = 1, as in the old system) with the congregation identity and the light/dark logos and favicon in private storage. Screen for `church_settings.manage` (pastor, secretary); every change recorded in the activity log. `church:import-legacy-settings` brings the one legacy row.

**D-F2 PDF documents** (`setasign/fpdf`, approved). A shared letterhead (church identity, logos, verification code and the sealed-ledger head hash), then the documents in one batch: weekly closure (with the photo of the paper book, private storage, SHA-256), monthly offering closure, monthly accounting report, receipts (IPUV paper 612×396 pt), tithes reports and audit reports. Each document is regenerated from the data (D6), never stored as the evidence.

### Cut-over (one command, ordered, idempotent, `--dry-run`)

`members → users → church settings → account codes → categories → offerings → weekly closures (seal) → monthly offering closures (legacy consolidated entries) → other accounting entries → monthly accounting closes (seal) → legacy chain archive + reconciliation → activity log → audits/acknowledgements`. Output: a report per step (imported, skipped with reason, differences = 0). The run is rejected unless every difference is 0.00 per currency (section 4.3).

## 4. Cent-exact verification (every entry, income, expense and balance)

Everything must match to the last decimal, per currency, with no tolerance. This applies once at the cut-over and permanently afterwards.

### 4.1 Arithmetic rules

- Amounts are `numeric(18,2)` in PostgreSQL and exact decimals in PHP (`brick/math` declared in `composer.json`, approved 2026-11-06) through `Shared\Money\Decimal`/`Money` (ported from safipuv-v4, `brick/math` `BigDecimal`, explicit scale and rounding mode). Never `float`, never `(float)`, never JavaScript `Number` for a value that is stored, sealed or printed.
- Every stored amount already has two decimals, so sums are exact: no intermediate rounding anywhere. Currencies are never converted inside the books.
- Screen, PDF and sealed payload read totals from the same query object; the frontend only formats them.
- `Decimal` rejects more than two decimals and non-numeric input instead of rounding silently.

### 4.2 Legacy baseline (measured on the legacy DB, 2026-11-06)

| Check                                                                 | Result                                                                          |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Each weekly closure (count, VES, USD, COP) vs its live offerings      | 96 of 96 equal                                                                  |
| Each monthly closure (VES, USD, COP) vs its live offerings            | 22 of 22 equal                                                                  |
| Offerings by category vs consolidated income entries, per month/code  | Equal for every closed month; only 2026-11 (open, not consolidated yet) differs |
| Closing balance of each month = I-001 of the next month, per currency | 22 of 22 pairs equal                                                            |
| Exactly one I-001 entry per month with entries                        | 23 of 23 months                                                                 |

The new system must reproduce this baseline exactly before anything else is compared.

### 4.3 Cut-over reconciliation (B-F6, gate of the cut-over command)

`reconcile:legacy` (read-only, re-runnable) computes every figure through **two independent paths**: an SQL aggregate and a PHP `BigDecimal` fold over the individual rows. Both must equal each other, and both must equal the legacy figure. Checks:

1. **Per record, 1:1 by `legacy_id`:** every live legacy offering has exactly one new offering with the same date, category, member and the three amounts; every live legacy entry has exactly one journal entry whose CAJA lines equal its three amounts and whose debits equal its credits per currency. Skipped legacy rows (soft-deleted) are listed one by one with their amounts.
2. **Per week:** offering count and the three totals: new data = legacy closure = legacy sealed weekly payload = new sealed weekly payload.
3. **Per month and account code:** income and expense per currency: new journal = legacy entries.
4. **Per month:** opening + income − expense = closing, per currency; the closing balance equals the next month's opening balance; offerings by category equal the posted offering income for that month.
5. **Global:** sum of all debits = sum of all credits per currency; total income − total expense since the first opening = current CAJA balance per currency = legacy balance.
6. **Sealed payloads:** monthly opening, income, expense, closing and by-code totals of the new chain = the legacy sealed monthly payloads for the 13 sealed months.

Output: one row per check and period with legacy, SQL, PHP and difference columns, plus a summary. The cut-over refuses to finish unless every difference is `0.00`.

### 4.4 Permanent controls after the cut-over

- **Per entry, in the database:** deferred constraint triggers (≥ 2 lines, debits = credits per currency, a reversal mirrors its original), append-only tables (B-F2).
- **Daily `ledger:verify`** (scheduled) and on demand in the Integrity center: global balance per currency, every entry balanced, every closed period's balance snapshot = the sum of its lines, carry-forward chain unbroken, weekly closure totals = their offerings, posted offering income = offerings by category, receipt totals = their entries, sealed payloads = live books (drift). Each run is recorded in the activity log.
- **Close gates:** a week or a month cannot be closed if any of those checks fails for that period; the error names the check and the difference.
- **Tests:** unit tests for `Decimal`/`MoneyBag` invariants (sums of many random two-decimal amounts, negative balances, three currencies); feature tests that a deliberately unbalanced entry, an altered line and a tampered block are each detected; golden fixtures with legacy months whose totals are asserted to the cent.

## 5. Suggested delivery order

1. A-F1, A-F2 (integrity foundations, pure domain + golden tests)
2. B-F1, B-F2 (chart + double-entry core)
3. C-F1, C-F1b, C-F2, C-F3, C-F3b (catalogs, exchange rates, offerings, weekly closures with sealing, monthly offering closure posting to the journal)
4. B-F3, B-F4 (journal, monthly close, ledger, reports)
5. C-F4, C-F5, C-F6 (receipts, tithes, dashboards)
6. B-F5, C-F7, A-F3, B-F6 + cut-over command (gated by B-F6)
7. A-F4, A-F5, A-F6 (integrity center, audits, anchoring)
8. D-F1, D-F2 (church settings and the PDF batch; D-F1 can move earlier if the PDFs are needed sooner)

Each phase ends with the quality gates of [rules/workflow.md](rules/workflow.md) green and its own commit(s).

## 6. Decisions

| #   | Decision                                                                                          | Recommendation                                                                                                               |
| --- | ------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| D1  | Double-entry (safipuv) or keep single-entry (old)                                                 | **Approved 2026-11-06: double-entry.** Reports still print the IPUV single-entry layout                                      |
| D2  | When offerings reach the journal: at weekly close or at monthly close (old)                       | **Approved 2026-11-06: at the monthly offering closure**, once every week touching the month is closed (section 3.1)         |
| D3  | Opening balance: derived from the previous close (safipuv) or written as I-001 each month (old)   | **Adopted 2026-11-06: derived**; shown as an "I-001 Saldo anterior" row so the printed report does not change                |
| D4  | Overdraft: reject an expense larger than the available cash per currency (safipuv) or allow (old) | **Approved 2026-11-06: reject.** History imported as it is; closed-week offerings count as available (section 3.1)           |
| D5  | Exchange rates                                                                                    | **Approved 2026-11-06: port safipuv-v4 exactly** (C-F1b). Rates inform and compare; the books stay per currency              |
| D6  | Legacy PDFs: copy the 82 stored files or regenerate                                               | **Approved 2026-11-06: regenerate** from imported data; the sealed payload is the evidence, not the file                     |
| D7  | Reopening a closed week/month                                                                     | **Approved 2026-11-06: never.** A mistake is corrected in an open month by a reversal that names the original and its reason |
