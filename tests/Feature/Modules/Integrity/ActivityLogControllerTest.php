<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use Inertia\Testing\AssertableInertia as Assert;

function userWithRole(UserRole $role, string $name = 'Usuario'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->forceFill(['role' => $role])->save();

    return $user;
}

test('only who holds activity_log.view can open the log', function (UserRole $role, int $status) {
    $this->actingAs(userWithRole($role))
        ->get(route('activity-log.index'))
        ->assertStatus($status);
})->with([
    'pastor' => [UserRole::Pastor, 200],
    'comptroller' => [UserRole::Comptroller, 403],
    'treasurer' => [UserRole::Treasurer, 403],
    'secretary' => [UserRole::Secretary, 403],
]);

test('lists the newest entries first with the author and the change labels in Spanish', function () {
    $pastor = userWithRole(UserRole::Pastor, 'Pedro Pastor');
    app(ActivityLog::class)->system('older', 'Registro más viejo.');

    $this->actingAs($pastor);
    app(ActivityLog::class)->record('member.updated', 'Actualizó al miembro Ana Pérez.', changes: [
        'full_name' => ['from' => 'Ana', 'to' => 'Ana Pérez'],
        'document_id' => ['from' => ChangeSet::REDACTED, 'to' => ChangeSet::REDACTED],
    ]);

    $this->get(route('activity-log.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity-log/index')
            ->where('entries.total', 2)
            ->where('entries.data.0.summary', 'Actualizó al miembro Ana Pérez.')
            ->where('entries.data.0.actor', 'Pedro Pastor')
            ->where('entries.data.0.actor_role', 'Pastor')
            ->where('entries.data.0.changes.0.label', 'Nombre completo')
            ->where('entries.data.0.changes.0.protected', false)
            ->where('entries.data.0.changes.1.field', 'document_id')
            ->where('entries.data.0.changes.1.protected', true)
            ->where('entries.data.1.actor', 'Sistema')
            ->has('categories', 4));
});

test('searches by person or action without minding accents or case', function () {
    $pastor = userWithRole(UserRole::Pastor, 'José Gómez');
    app(ActivityLog::class)->system('members.imported_legacy', 'Importó los miembros del sistema anterior.');

    $this->actingAs($pastor);
    app(ActivityLog::class)->record('member.created', 'Registró al miembro Ana Pérez.');

    $search = fn (string $term) => $this->get(route('activity-log.index', ['search' => $term]));

    $search('perez')->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    $search('JOSE GOMEZ')->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    $search('importó')->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
});

test('filters by category and ignores tampered values', function () {
    $pastor = userWithRole(UserRole::Pastor);
    app(ActivityLog::class)->system('a', 'Sistema.');

    $this->actingAs($pastor);
    app(ActivityLog::class)->security('auth.login', 'Inició sesión.', $pastor);

    $this->get(route('activity-log.index', ['category' => 'security']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.category', 'security')
            ->where('filters.category', 'security'));

    $this->get(route('activity-log.index', ['category' => 'drop table', 'sort' => 'hash', 'direction' => 'sideways']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 2)
            ->where('filters.category', 'all')
            ->where('filters.sort', 'occurred')
            ->where('filters.direction', 'desc'));
});
