<?php

namespace App\Console\Commands;

use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Members\Infrastructure\Models\MemberChild;
use App\Modules\Members\Infrastructure\Models\MemberRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies the member registry from the old system (TanStack/Drizzle, integer ids)
 * into the new UUIDv7 schema.
 *
 * - Idempotent: rows are matched by `legacy_id`, so it can be re-run to refresh.
 * - New ids are UUIDv7 built from the legacy `created_at`, so the id order follows
 *   the original registration order.
 * - Rows are never deleted in the new database; soft deletes are carried over.
 * - Photos are not copied (only the storage key existed in the old database).
 */
#[Signature('members:import-legacy {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import members, children and roles from the old system database')]
class ImportLegacyMembers extends Command
{
    /** @var array<string, int> */
    private array $created = ['members' => 0, 'children' => 0, 'roles' => 0];

    /** @var array<string, int> */
    private array $updated = ['members' => 0, 'children' => 0, 'roles' => 0];

    public function handle(): int
    {
        $legacy = DB::connection((string) $this->option('connection'));

        $members = $legacy->table('members')->orderBy('id')->get();
        $children = $legacy->table('member_children')->orderBy('id')->get();
        $roles = $legacy->table('member_roles')->orderBy('id')->get();

        $this->components->info(sprintf(
            'Legacy source: %d members, %d children, %d roles.',
            $members->count(),
            $children->count(),
            $roles->count(),
        ));

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($members, $children, $roles): void {
            $memberIds = $this->importMembers($members);
            $this->linkSpouses($members, $memberIds);
            $this->importChildren($children, $memberIds);
            $this->importRoles($roles, $memberIds);
        });

        $this->components->twoColumnDetail('Members', $this->summary('members'));
        $this->components->twoColumnDetail('Children', $this->summary('children'));
        $this->components->twoColumnDetail('Roles', $this->summary('roles'));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, string> legacy id => UUID
     */
    private function importMembers(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $member = $this->upsert(Member::class, 'members', $row, [
                'full_name' => $row->full_name,
                'document_type' => $row->document_type,
                'document_id' => $row->document_id,
                'sex' => $row->sex,
                'birth_date' => $row->birth_date,
                'birth_place_city' => $row->birth_place_city,
                'birth_place_state' => $row->birth_place_state,
                'phone' => $row->phone,
                'landline_phone' => $row->landline_phone,
                'email' => $row->email,
                'address_state' => $row->address_state,
                'address_municipality' => $row->address_municipality,
                'address_parish' => $row->address_parish,
                'address' => $row->address,
                'education_level' => $row->education_level,
                'profession' => $row->profession,
                'occupation' => $row->occupation,
                'marital_status' => $row->marital_status,
                'baptism_date' => $row->baptism_date,
                'baptism_place' => $row->baptism_place,
                'baptism_pastor' => $row->baptism_pastor,
                'holy_spirit_date' => $row->holy_spirit_date,
                'joined_date' => $row->joined_date,
                'status' => $row->status,
                'pastor_notes' => $row->pastor_notes,
                'deleted_at' => $row->deleted_at,
            ]);

            $ids[(int) $row->id] = $member->getKey();
        }

        return $ids;
    }

    /**
     * Spouses reference other members, so they are linked once every id exists.
     * A plain query is used to keep the imported updated_at untouched.
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  array<int, string>  $memberIds
     */
    private function linkSpouses(Collection $rows, array $memberIds): void
    {
        foreach ($rows as $row) {
            DB::table('members')
                ->where('id', $memberIds[(int) $row->id])
                ->update(['spouse_id' => $row->spouse_id === null ? null : ($memberIds[(int) $row->spouse_id] ?? null)]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  array<int, string>  $memberIds
     */
    private function importChildren(Collection $rows, array $memberIds): void
    {
        foreach ($rows as $row) {
            $this->upsert(MemberChild::class, 'children', $row, [
                'member_id' => $memberIds[(int) $row->member_id],
                'name' => $row->name,
                'birth_date' => $row->birth_date,
            ]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  array<int, string>  $memberIds
     */
    private function importRoles(Collection $rows, array $memberIds): void
    {
        foreach ($rows as $row) {
            $this->upsert(MemberRole::class, 'roles', $row, [
                'member_id' => $memberIds[(int) $row->member_id],
                'role' => $row->role,
                'since' => $row->since,
                'until' => $row->until,
            ]);
        }
    }

    /**
     * Creates or refreshes the row that matches the legacy id, keeping the
     * legacy timestamps. New rows get a UUIDv7 built from the legacy created_at.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function upsert(string $model, string $counter, stdClass $row, array $attributes): Model
    {
        /** @var TModel|null $existing */
        $existing = $model::query()->withoutGlobalScopes()->where('legacy_id', $row->id)->first();
        $record = $existing ?? new $model;

        $createdAt = Carbon::parse($row->created_at);

        if ($existing === null) {
            $record->setAttribute($record->getKeyName(), (string) Str::uuid7($createdAt));
        }

        $record->timestamps = false;
        $record->forceFill([
            ...$attributes,
            'legacy_id' => (int) $row->id,
            'created_at' => $createdAt,
            'updated_at' => Carbon::parse($row->updated_at ?? $row->created_at),
        ])->save();

        $existing === null ? $this->created[$counter]++ : $this->updated[$counter]++;

        return $record;
    }

    private function summary(string $counter): string
    {
        return sprintf('%d created, %d updated', $this->created[$counter], $this->updated[$counter]);
    }
}
