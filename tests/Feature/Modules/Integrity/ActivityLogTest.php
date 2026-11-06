<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Integrity\Application\Actions\VerifyActivityChain;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Domain\Enums\ActivityChannel;
use App\Modules\Integrity\Infrastructure\ActivityContext;
use App\Modules\Integrity\Infrastructure\DatabaseActivityLog;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function activityLog(): ActivityLog
{
    return app(ActivityLog::class);
}

test('a signed-in user is recorded as the actor with a snapshot of name and role', function () {
    $user = User::factory()->create(['name' => 'Rosa Tesorera']);
    $user->forceFill(['role' => UserRole::Treasurer])->save();
    $member = Member::factory()->create();

    $this->actingAs($user);
    activityLog()->record('member.updated', 'Actualizó al miembro.', $member, ['full_name' => ['from' => 'A', 'to' => 'B']]);

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->category)->toBe(ActivityCategory::Data)
        ->and($entry->channel)->toBe(ActivityChannel::Web)
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->actor_name)->toBe('Rosa Tesorera')
        ->and($entry->actor_role)->toBe('tesorero')
        ->and($entry->subject_type)->toBe('members')
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->changes)->toEqual(['full_name' => ['from' => 'A', 'to' => 'B']])
        ->and($entry->request_id)->not->toBeNull();
});

test('without a signed-in user the console is recorded as the system channel', function () {
    activityLog()->system('members.imported', 'Importó 3 miembros.', details: ['imported' => 3]);

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->channel)->toBe(ActivityChannel::System)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->category)->toBe(ActivityCategory::System);
});

test('each entry is chained to the previous one by the database', function () {
    activityLog()->system('first', 'Primero.');
    activityLog()->system('second', 'Segundo.');

    [$first, $second] = ActivityLogEntry::query()->orderBy('seq')->get()->all();

    expect($first->previous_hash)->toBeNull()
        ->and($second->seq)->toBeGreaterThan($first->seq)
        ->and($second->previous_hash)->toBe($first->hash)
        ->and(strlen($second->hash))->toBe(64);

    $recomputed = DB::selectOne(
        'SELECT activity_log_hash(previous_hash, a) AS hash FROM activity_log a WHERE id = ?',
        [$second->id],
    )->hash;

    expect($recomputed)->toBe($second->hash);
});

test('the application cannot choose the chain values', function () {
    $entry = ActivityLogEntry::query()->forceCreate([
        'category' => ActivityCategory::System,
        'channel' => ActivityChannel::System,
        'action' => 'forged',
        'summary' => 'Intento de falsificar.',
        'seq' => 999,
        'previous_hash' => str_repeat('a', 64),
        'hash' => str_repeat('b', 64),
    ])->refresh();

    expect($entry->seq)->not->toBe(999)
        ->and($entry->previous_hash)->toBeNull()
        ->and($entry->hash)->not->toBe(str_repeat('b', 64));
});

test('entries cannot be changed, deleted or truncated', function (string $statement) {
    activityLog()->system('kept', 'Se conserva.');

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class, 'append-only');
})->with([
    'update' => "UPDATE activity_log SET summary = 'otro'",
    'delete' => 'DELETE FROM activity_log',
    'truncate' => 'TRUNCATE activity_log',
]);

test('a data write without an actor outside the console is refused', function () {
    app()->instance('env', 'production');
    $app = Mockery::mock(app())->makePartial();
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    $log = new DatabaseActivityLog(
        app('auth'),
        app(ActivityContext::class),
        $app,
    );

    expect(fn () => $log->record('member.updated', 'Sin autor.'))->toThrow(LogicException::class);
    expect(ActivityLogEntry::query()->count())->toBe(0);
});

test('a security fact without a signed-in user is recorded as a guest', function () {
    $app = Mockery::mock(app())->makePartial();
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    $log = new DatabaseActivityLog(
        app('auth'),
        app(ActivityContext::class),
        $app,
    );
    $log->security('auth.failed', 'Intento fallido de inicio de sesión.');

    expect(ActivityLogEntry::query()->sole()->channel)->toBe(ActivityChannel::Guest);
});

test('verification finds an intact chain and reports its head', function () {
    activityLog()->system('a', 'A.');
    activityLog()->system('b', 'B.');
    $head = ActivityLogEntry::query()->orderByDesc('seq')->first();

    $result = app(VerifyActivityChain::class)();

    expect($result)->toBe([
        'rows' => 2,
        'broken' => [],
        'head_seq' => $head->seq,
        'head_hash' => $head->hash,
    ]);
});

/**
 * Simulates someone with database access editing the table by hand: the owner can disable
 * the guard trigger (inside the test transaction, so it is rolled back afterwards).
 */
function tamperWithActivityLog(string $statement): void
{
    DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_no_update');
    DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_no_delete');
    DB::statement($statement);
    DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_no_update');
    DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_no_delete');
}

test('verification detects a row edited directly in the database', function () {
    activityLog()->system('a', 'A.');
    activityLog()->system('b', 'B.');
    activityLog()->system('c', 'C.');
    $edited = ActivityLogEntry::query()->where('action', 'b')->sole();

    tamperWithActivityLog("UPDATE activity_log SET summary = 'Otra cosa.' WHERE action = 'b'");

    expect(app(VerifyActivityChain::class)()['broken'])->toBe([$edited->seq]);
});

test('verification detects a row removed from the middle of the chain', function () {
    activityLog()->system('a', 'A.');
    activityLog()->system('b', 'B.');
    activityLog()->system('c', 'C.');
    $next = ActivityLogEntry::query()->where('action', 'c')->sole();

    tamperWithActivityLog("DELETE FROM activity_log WHERE action = 'b'");

    expect(app(VerifyActivityChain::class)()['broken'])->toBe([$next->seq]);
});

test('the verify command records the verification and fails on a broken chain', function () {
    activityLog()->system('a', 'A.');

    $this->artisan('activity:verify')->assertSuccessful();

    $verification = ActivityLogEntry::query()->where('action', 'activity_log.verified')->sole();
    expect($verification->changes['broken'])->toBe([]);

    tamperWithActivityLog("UPDATE activity_log SET summary = 'Otra cosa.' WHERE action = 'a'");

    $this->artisan('activity:verify')->assertFailed();
});

test('a command run from the terminal is recorded with its name as the route', function () {
    event(new CommandStarting(
        'members:import-legacy',
        new ArrayInput([]),
        new NullOutput,
    ));

    activityLog()->system('members.imported', 'Importó miembros.');

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->method)->toBe('CLI')
        ->and($entry->route)->toBe('artisan members:import-legacy');
});

test('a web request is recorded with its route, IP and correlation id', function () {
    $user = User::factory()->create();
    $user->forceFill(['role' => UserRole::Pastor])->save();

    $requestId = (string) Str::uuid7();

    $response = $this->actingAs($user)
        ->withHeader('X-Request-Id', $requestId)
        ->get(route('dashboard'));

    $response->assertOk()->assertHeader('X-Request-Id', $requestId);

    activityLog()->record('test.recorded', 'Registro de prueba.');

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->request_id)->toBe($requestId)
        ->and($entry->route)->toBe('dashboard')
        ->and($entry->method)->toBe('GET')
        ->and($entry->ip)->toBe('127.0.0.1');
});
