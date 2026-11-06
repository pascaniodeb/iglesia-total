<?php

use App\Enums\Permission;
use App\Enums\UserRole;

/**
 * The permission matrix, written out in full on purpose: this table is the specification
 * of who can do what. Changing a role's access means changing it here too.
 */
dataset('role permissions', [
    'pastor holds everything' => [UserRole::Pastor, array_column(Permission::cases(), 'value')],
    'assistant' => [UserRole::Assistant, [
        'dashboard.view',
        'members.view',
    ]],
    'secretary handles people' => [UserRole::Secretary, [
        'dashboard.view',
        'members.view', 'members.manage',
        'member_reports.view',
        'church_settings.manage',
        'assets.view', 'assets.manage',
    ]],
    'treasurer handles money' => [UserRole::Treasurer, [
        'dashboard.view',
        'offerings.view', 'offerings.manage',
        'closures.view', 'closures.manage',
        'accounting.view', 'accounting.manage',
        'catalogs.view',
    ]],
    'comptroller oversees' => [UserRole::Comptroller, [
        'dashboard.view',
        'members.view',
        'member_reports.view',
        'offerings.view',
        'closures.view',
        'accounting.view',
        'catalogs.view',
        'sealed_ledger.view',
        'audits.view', 'audits.record',
    ]],
    'member has no module access' => [UserRole::Member, []],
]);

test('holds exactly the permissions of its office', function (UserRole $role, array $expected) {
    $held = array_map(fn (Permission $permission) => $permission->value, $role->permissions());

    expect($held)->toEqualCanonicalizing($expected);
})->with('role permissions');

test('lets whoever can manage a module also view it', function (UserRole $role) {
    $managesWithoutViewing = [];

    foreach ($role->permissions() as $permission) {
        if (! str_ends_with($permission->value, '.manage')) {
            continue;
        }

        $view = Permission::tryFrom(str_replace('.manage', '.view', $permission->value));

        if ($view !== null && ! $role->grants($view)) {
            $managesWithoutViewing[] = "{$permission->value} without {$view->value}";
        }
    }

    expect($managesWithoutViewing)->toBe([]);
})->with(array_column(UserRole::cases(), null, 'value'));

test('leaves no permission without an owner', function () {
    $held = array_map(
        fn (Permission $permission) => $permission->value,
        array_merge(...array_map(fn (UserRole $role) => $role->permissions(), UserRole::cases())),
    );

    $orphans = array_diff(array_column(Permission::cases(), 'value'), $held);

    expect($orphans)->toBe([]);
});

test('keeps pastoral and administrative permissions for the pastor alone', function (UserRole $role) {
    $reserved = [
        Permission::TithesView,
        Permission::TithesManage,
        Permission::UsersManage,
        Permission::ActivityLogView,
        Permission::ReceiptsAdminister,
        Permission::CatalogsManage,
    ];

    foreach ($reserved as $permission) {
        expect($role->grants($permission))->toBeFalse("{$role->value} must not hold {$permission->value}");
    }
})->with(array_filter(
    array_column(UserRole::cases(), null, 'value'),
    fn (UserRole $role) => $role !== UserRole::Pastor,
));

test('maps the role values of the old system', function (string $legacy, UserRole $expected) {
    expect(UserRole::fromLegacy($legacy))->toBe($expected);
})->with([
    'pastor' => ['pastor', UserRole::Pastor],
    'secretaria' => ['secretaria', UserRole::Secretary],
    'tesoreria' => ['tesoreria', UserRole::Treasurer],
    'contraloria' => ['contraloria', UserRole::Comptroller],
    'miembro' => ['miembro', UserRole::Member],
]);

test('rejects a role value the old system never had', function () {
    expect(fn () => UserRole::fromLegacy('administrador'))
        ->toThrow(InvalidArgumentException::class, 'Unknown legacy role [administrador].');
});
