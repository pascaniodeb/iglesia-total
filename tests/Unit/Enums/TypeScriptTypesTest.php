<?php

use App\Enums\Permission;
use App\Enums\UserRole;

/**
 * @return list<string> the string literals of `export type <name> = 'a' | 'b';`
 */
function typeScriptUnion(string $name): array
{
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/types/auth.ts');

    preg_match("/export type {$name} =((?:\\s*\\|?\\s*'[^']+')+);/", $source, $declaration);
    preg_match_all("/'([^']+)'/", $declaration[1] ?? '', $values);

    return $values[1];
}

test('keeps the TypeScript role type in sync with the PHP enum', function () {
    expect(typeScriptUnion('UserRole'))
        ->toEqualCanonicalizing(array_column(UserRole::cases(), 'value'));
});

test('keeps the TypeScript permission type in sync with the PHP enum', function () {
    expect(typeScriptUnion('Permission'))
        ->toEqualCanonicalizing(array_column(Permission::cases(), 'value'));
});
