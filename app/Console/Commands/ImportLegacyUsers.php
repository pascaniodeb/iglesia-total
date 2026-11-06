<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Copies the accounts of the old system (better-auth, integer ids) into the new
 * schema. Run `members:import-legacy` first so accounts can be linked to their member.
 *
 * - Idempotent: matched by `legacy_id`. On re-runs it refreshes name, email, role,
 *   active flag and member link. Run it only until the cut-over to the new system,
 *   afterwards it would overwrite changes made here.
 * - Passwords are NOT copied: the old ones are scrypt hashes PHP cannot verify
 *   natively. New accounts get an unusable random password; set a real one with
 *   `users:set-password` or the password reset flow. Existing accounts keep theirs.
 */
#[Signature('users:import-legacy {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import user accounts (without passwords) from the old system database')]
class ImportLegacyUsers extends Command
{
    public function handle(): int
    {
        $legacy = DB::connection((string) $this->option('connection'));
        $rows = $legacy->table('users')->orderBy('id')->get();

        $this->components->info(sprintf('Legacy source: %d users.', $rows->count()));

        // Fail before writing anything if a role cannot be mapped.
        foreach ($rows as $row) {
            try {
                UserRole::fromLegacy((string) $row->role);
            } catch (InvalidArgumentException $exception) {
                $this->components->error("User {$row->id}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        /** @var array<int, string> $memberIds legacy member id => UUID */
        $memberIds = Member::withTrashed()->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $created = 0;
        $updated = 0;
        $unlinked = 0;

        DB::transaction(function () use ($rows, $memberIds, &$created, &$updated, &$unlinked): void {
            foreach ($rows as $row) {
                $memberId = $row->member_id === null ? null : ($memberIds[(int) $row->member_id] ?? null);

                if ($row->member_id !== null && $memberId === null) {
                    $unlinked++;
                    $this->components->warn("User {$row->id}: member {$row->member_id} was not found; run members:import-legacy first.");
                }

                $user = User::query()->where('legacy_id', $row->id)->first();
                $createdAt = Carbon::parse($row->created_at);

                if ($user === null) {
                    $user = new User;
                    $user->setAttribute($user->getKeyName(), (string) Str::uuid7($createdAt));
                    $user->password = Hash::make(Str::random(64));
                    $created++;
                } else {
                    $updated++;
                }

                $user->timestamps = false;
                $user->forceFill([
                    'legacy_id' => (int) $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'role' => UserRole::fromLegacy((string) $row->role),
                    'is_active' => (bool) $row->is_active,
                    'member_id' => $memberId,
                    'email_verified_at' => $user->email_verified_at ?? ($row->email_verified ? Carbon::parse($row->updated_at) : null),
                    'created_at' => $createdAt,
                    'updated_at' => Carbon::parse($row->updated_at),
                ])->save();
            }
        });

        $this->components->twoColumnDetail('Users', "{$created} created, {$updated} updated");

        if ($unlinked > 0) {
            $this->components->warn("{$unlinked} user(s) left without a member link.");
        }

        return self::SUCCESS;
    }
}
