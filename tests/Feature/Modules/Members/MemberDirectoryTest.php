<?php

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Infrastructure\Models\Member;

test('the registry lists every member not in the trash, by name', function () {
    Member::factory()->create(['full_name' => 'Zulay Rondón']);
    Member::factory()->create(['full_name' => 'Ana Pérez']);
    Member::factory()->create(['full_name' => 'Borrado Gómez'])->delete();

    $names = array_map(fn ($member) => $member->name, app(MemberDirectory::class)->registry());

    expect($names)->toBe(['Ana Pérez', 'Zulay Rondón']);
});

test('the registry marks its members as part of it', function () {
    Member::factory()->create();

    expect(app(MemberDirectory::class)->registry()[0]->isInRegistry)->toBeTrue();
});
