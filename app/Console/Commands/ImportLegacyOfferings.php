<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies the offerings of the old system, as history. Each one keeps its date, giver, category,
 * concept, amounts and creation dates; the offerings sent to the trash in the old system are not
 * imported (they are listed, one by one, with their amounts).
 *
 * Run members:import-legacy, users:import-legacy and treasury:import-legacy-categories first.
 * Idempotent by legacy_id: an offering already imported is left alone, and one that differs from
 * the old system's is a conflict (history is never rewritten). Nothing is written if any
 * offering points to a member or a category that was not imported, and the transaction is undone
 * unless the totals per currency of the old system and of the new one are equal to the cent.
 * Offerings must be imported before the weekly closures: a closed week locks them.
 */
#[Signature('treasury:import-legacy-offerings {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import the offerings from the old system database')]
class ImportLegacyOfferings extends Command
{
    private const array CURRENCIES = ['ves', 'usd', 'cop'];

    public function handle(ActivityLog $activityLog): int
    {
        $legacy = DB::connection((string) $this->option('connection'));
        $rows = $legacy->table('offerings')->whereNull('deleted_at')->orderBy('date')->orderBy('id')->get();
        $trashed = $legacy->table('offerings')->whereNotNull('deleted_at')->orderBy('id')->get();

        $this->components->info("Legacy source: {$rows->count()} live offerings, {$trashed->count()} in the trash (not imported).");

        /** @var array<int, string> $members */
        $members = DB::table('members')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        /** @var array<int, string> $categories */
        $categories = DB::table('offering_categories')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        /** @var array<int, string> $users */
        $users = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();

        $missing = [];

        foreach ($rows as $row) {
            if ($row->member_id !== null && ! isset($members[(int) $row->member_id])) {
                $missing["member {$row->member_id}"][] = $row->id;
            }

            if (! isset($categories[(int) $row->offering_category_id])) {
                $missing["category {$row->offering_category_id}"][] = $row->id;
            }
        }

        if ($missing !== []) {
            foreach ($missing as $what => $ids) {
                $this->components->error("Not imported yet: {$what} (offerings ".implode(', ', array_slice($ids, 0, 8)).(count($ids) > 8 ? ', …' : '').').');
            }

            $this->components->error('Run members:import-legacy and treasury:import-legacy-categories first. Nothing was written.');

            return self::FAILURE;
        }

        $existing = Offering::query()->withTrashed()->whereNotNull('legacy_id')->get()->keyBy('legacy_id');
        $conflicts = [];
        $already = 0;
        $toCreate = [];

        foreach ($rows as $row) {
            $current = $existing->get((int) $row->id);

            if ($current === null) {
                $toCreate[] = $row;

                continue;
            }

            self::matches($current, $row, $members, $categories) ? $already++ : $conflicts[] = $row->id;
        }

        if ($conflicts !== []) {
            $this->components->error('These offerings were already imported and now differ from the old system (history is never rewritten): '.implode(', ', $conflicts).'.');

            return self::FAILURE;
        }

        $unmappedUsers = count(array_filter($toCreate, fn (stdClass $row): bool => $row->created_by !== null && ! isset($users[(int) $row->created_by])));

        $this->components->twoColumnDetail('To import', (string) count($toCreate));
        $this->components->twoColumnDetail('Already imported', (string) $already);
        $this->components->twoColumnDetail('Recorded by a user that was not imported', "{$unmappedUsers} (left without author)");

        foreach ($trashed as $row) {
            $this->components->twoColumnDetail("Skipped (in the old trash): #{$row->id} of {$row->date}", "VES {$row->amount_ves} · USD {$row->amount_usd} · COP {$row->amount_cop}");
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        try {
            $report = DB::transaction(function () use ($toCreate, $members, $categories, $users, $legacy, $activityLog, $trashed): array {
                foreach ($toCreate as $row) {
                    $offering = new Offering;
                    $offering->setAttribute($offering->getKeyName(), (string) Str::uuid7(Carbon::parse($row->created_at)));
                    $offering->forceFill([
                        'legacy_id' => (int) $row->id,
                        'offering_date' => $row->date,
                        'member_id' => $row->member_id === null ? null : $members[(int) $row->member_id],
                        'offering_category_id' => $categories[(int) $row->offering_category_id],
                        'concept' => $row->concept,
                        'amount_ves' => $row->amount_ves,
                        'amount_usd' => $row->amount_usd,
                        'amount_cop' => $row->amount_cop,
                        'created_by' => $row->created_by === null ? null : ($users[(int) $row->created_by] ?? null),
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ])->save();
                }

                $sql = 'count(*) AS n, coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves, coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd, coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop';
                $old = $legacy->table('offerings')->whereNull('deleted_at')->selectRaw($sql)->first();
                $new = DB::table('offerings')->whereNotNull('legacy_id')->whereNull('deleted_at')->selectRaw($sql)->first();

                $report = ['count' => [(string) $old->n, (string) $new->n]];

                foreach (self::CURRENCIES as $currency) {
                    $report[$currency] = [(string) $old->{$currency}, (string) $new->{$currency}];
                }

                foreach ($report as $pair) {
                    if ($pair[0] !== $pair[1]) {
                        throw new ImportDifference($report);
                    }
                }

                if ($toCreate !== []) {
                    $activityLog->system(
                        'offerings.imported_legacy',
                        __('Imported the offerings of the old system: :count offerings.', ['count' => count($toCreate)]),
                        details: [
                            'imported' => count($toCreate),
                            'skipped_in_trash' => $trashed->pluck('id')->all(),
                            'totals' => ['ves' => $new->ves, 'usd' => $new->usd, 'cop' => $new->cop],
                        ],
                    );
                }

                return $report;
            });
        } catch (ImportDifference $difference) {
            $report = $difference->report;
            $failed = true;
        }

        foreach (['count' => 'Offerings', 'ves' => 'VES', 'usd' => 'USD', 'cop' => 'COP'] as $key => $label) {
            $this->components->twoColumnDetail("{$label}: old system | new system", "{$report[$key][0]} | {$report[$key][1]}");
        }

        if ($failed ?? false) {
            $this->components->error('The totals of the old and the new system differ: everything was undone.');

            return self::FAILURE;
        }

        $this->components->info('Offerings imported: every total equals the old system to the cent.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $members
     * @param  array<int, string>  $categories
     */
    private static function matches(Offering $current, stdClass $row, array $members, array $categories): bool
    {
        return $current->offering_date->toDateString() === (string) $row->date
            && $current->member_id === ($row->member_id === null ? null : $members[(int) $row->member_id])
            && $current->offering_category_id === $categories[(int) $row->offering_category_id]
            && $current->concept === $row->concept
            && $current->amount_ves === Money::of((string) $row->amount_ves, Currency::VES)->amount()
            && $current->amount_usd === Money::of((string) $row->amount_usd, Currency::USD)->amount()
            && $current->amount_cop === Money::of((string) $row->amount_cop, Currency::COP)->amount()
            && $current->deleted_at === null;
    }
}
