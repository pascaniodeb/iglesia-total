<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LegacyActivityEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 14:00:00');
    createLegacySource();
    createLegacyAuditTables();
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyActivity(int $id, array $attributes = []): void
{
    DB::connection('legacy')->table('activity_log')->insert([
        'id' => $id,
        'occurred_at' => '2026-08-22 22:57:54.414246+00',
        'actor_id' => 1,
        'actor_name' => 'Pablo Ascanio',
        'actor_role' => 'pastor',
        'action' => 'upload',
        'entity' => 'branding_asset',
        'entity_id' => 'logo-light',
        'summary' => 'Subió el recurso de marca «logo-light».',
        'before' => null,
        'after' => json_encode(['name' => 'logo']),
        'diff' => null,
        'ip' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        ...$attributes,
    ]);
}

function archiveCheck(): array
{
    return collect(app(IntegrityChecks::class)->run())->firstWhere('id', 'activity.legacy-archive');
}

test('the old activity log is archived verbatim and its digest goes into the new chained log', function () {
    legacyActivity(20);
    legacyActivity(21, ['action' => 'create', 'actor_name' => 'Javier', 'occurred_at' => '2026-08-23 01:00:00+00', 'summary' => 'Registró un miembro.', 'after' => json_encode(['full_name' => 'Ana Pérez', 'tags' => [1, 2]])]);

    $this->artisan('activity:import-legacy')->assertSuccessful();

    $entry = LegacyActivityEntry::query()->where('legacy_id', 21)->sole();
    $recorded = ActivityLogEntry::query()->where('action', 'activity.legacy_archived')->sole();

    expect(LegacyActivityEntry::query()->count())->toBe(2)
        ->and($entry->actor_name)->toBe('Javier')
        ->and($entry->summary)->toBe('Registró un miembro.')
        ->and($entry->after)->toEqual(['full_name' => 'Ana Pérez', 'tags' => [1, 2]])
        ->and($entry->occurred_at->utc()->toDateTimeString())->toBe('2026-08-23 01:00:00')
        ->and($recorded->changes['count'])->toBe(2)
        ->and($recorded->changes['first'])->toBe(20)
        ->and($recorded->changes['last'])->toBe(21)
        ->and($recorded->changes['digest'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(archiveCheck()['passed'])->toBeTrue();
});

test('it is idempotent and only adds what is missing', function () {
    legacyActivity(20);
    $this->artisan('activity:import-legacy')->assertSuccessful();
    $this->artisan('activity:import-legacy')->assertSuccessful();

    expect(ActivityLogEntry::query()->where('action', 'activity.legacy_archived')->count())->toBe(1);

    legacyActivity(21);
    $this->artisan('activity:import-legacy')->assertSuccessful();

    expect(LegacyActivityEntry::query()->count())->toBe(2)
        ->and(ActivityLogEntry::query()->where('action', 'activity.legacy_archived')->count())->toBe(2)
        ->and(archiveCheck()['passed'])->toBeTrue();
});

test('history is never rewritten: an archived entry that differs from the old one refuses the run', function () {
    legacyActivity(20);
    $this->artisan('activity:import-legacy')->assertSuccessful();

    DB::connection('legacy')->table('activity_log')->where('id', 20)->update(['summary' => 'Otro texto.']);

    $this->artisan('activity:import-legacy')->assertFailed();
    expect(LegacyActivityEntry::query()->sole()->summary)->toBe('Subió el recurso de marca «logo-light».');
});

test('a dry run writes nothing', function () {
    legacyActivity(20);

    $this->artisan('activity:import-legacy', ['--dry-run' => true])->assertSuccessful();

    expect(LegacyActivityEntry::query()->count())->toBe(0)
        ->and(ActivityLogEntry::query()->where('action', 'activity.legacy_archived')->count())->toBe(0);
});

test('the archive cannot be edited or erased', function () {
    legacyActivity(20);
    $this->artisan('activity:import-legacy')->assertSuccessful();

    expect(fn () => DB::transaction(fn () => DB::table('legacy_activity_log')->update(['summary' => 'x'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('legacy_activity_log')->delete()))->toThrow(QueryException::class, 'append-only');
});

test('the integrity center notices an archive that was changed behind the guards', function () {
    legacyActivity(20);
    $this->artisan('activity:import-legacy')->assertSuccessful();

    DB::statement('ALTER TABLE legacy_activity_log DISABLE TRIGGER legacy_activity_log_no_update');
    DB::statement("UPDATE legacy_activity_log SET summary = 'Reescrito'");
    DB::statement('ALTER TABLE legacy_activity_log ENABLE TRIGGER legacy_activity_log_no_update');

    $check = archiveCheck();

    expect($check['passed'])->toBeFalse()
        ->and($check['findings'][0])->toContain('huella');
});

test('an archive without a record of its digest is a finding, and an empty archive is not', function () {
    expect(archiveCheck()['passed'])->toBeTrue();

    DB::table('legacy_activity_log')->insert(['id' => (string) Str::uuid7(), 'legacy_id' => 1, 'occurred_at' => now(), 'actor_name' => 'x', 'actor_role' => 'x', 'action' => 'x', 'entity' => 'x', 'summary' => 'x']);

    expect(archiveCheck()['passed'])->toBeFalse();
});

test('the archived log is shown read-only to whoever may read the activity log', function () {
    legacyActivity(20);
    legacyActivity(21, ['summary' => 'Registró un miembro.', 'occurred_at' => '2026-08-23 01:00:00+00']);
    $this->artisan('activity:import-legacy')->assertSuccessful();

    signInAs(UserRole::Pastor);
    $this->get(route('activity-log.legacy'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('activity-log/legacy')
        ->where('entries.total', 2)
        ->where('entries.data.0.summary', 'Registró un miembro.'));
    $this->get(route('activity-log.legacy', ['search' => 'miembro']))->assertInertia(fn (AssertableInertia $page) => $page->where('entries.total', 1));

    signInAs(UserRole::Treasurer);
    $this->get(route('activity-log.legacy'))->assertForbidden();
});
