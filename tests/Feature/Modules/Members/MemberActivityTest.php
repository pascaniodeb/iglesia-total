<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Application\Actions\DeleteMember;
use App\Modules\Members\Application\Actions\RestoreMember;
use App\Modules\Members\Application\Actions\SaveMember;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $secretary = User::factory()->create(['name' => 'Marta Secretaria']);
    $secretary->forceFill(['role' => UserRole::Secretary])->save();
    $this->actingAs($secretary);
});

test('registering a member is recorded with its fields but never its personal data', function () {
    $member = app(SaveMember::class)(null, [
        'full_name' => 'Ana Pérez',
        'document_type' => 'V',
        'document_id' => '12345678',
        'phone' => '0414-1234567',
        'status' => 'activo',
        'children' => [['name' => 'Luisito Pérez', 'birth_date' => '2018-05-01']],
        'roles' => [['role' => 'Diaconisa', 'since' => '2020-01-01', 'until' => null]],
    ]);

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->action)->toBe('member.created')
        ->and($entry->summary)->toBe('Registró al miembro Ana Pérez.')
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->actor_name)->toBe('Marta Secretaria')
        ->and($entry->changes['full_name'])->toEqual(['from' => null, 'to' => 'Ana Pérez'])
        ->and($entry->changes['document_id'])->toEqual(['from' => null, 'to' => ChangeSet::REDACTED])
        ->and($entry->changes['children'])->toEqual(['from' => null, 'to' => 1])
        ->and($entry->changes['roles'])->toEqual(['from' => null, 'to' => 'Diaconisa']);

    $raw = json_encode($entry->changes);
    expect($raw)->not->toContain('12345678')
        ->not->toContain('0414')
        ->not->toContain('Luisito');
});

test('updating a member records only what changed', function () {
    $member = Member::factory()->create(['full_name' => 'Ana Pérez', 'profession' => 'Docente']);

    app(SaveMember::class)($member, [
        'full_name' => 'Ana Pérez',
        'profession' => 'Abogada',
        'children' => [],
        'roles' => [],
    ]);

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->action)->toBe('member.updated')
        ->and($entry->changes)->toHaveKey('profession')
        ->and($entry->changes['profession'])->toEqual(['from' => 'Docente', 'to' => 'Abogada'])
        ->and($entry->changes)->not->toHaveKey('full_name');
});

test('saving a member without changes writes nothing to the log', function () {
    $member = Member::factory()->create(['full_name' => 'Ana Pérez']);

    app(SaveMember::class)($member, [
        ...collect($member->only(['full_name', 'document_type', 'document_id', 'sex', 'status', 'profession', 'occupation']))->all(),
        'children' => [],
        'roles' => [],
    ]);

    expect(ActivityLogEntry::query()->count())->toBe(0);
});

test('a marriage is recorded on both records', function () {
    $ana = Member::factory()->create(['full_name' => 'Ana Pérez']);
    $luis = Member::factory()->create(['full_name' => 'Luis Gómez']);

    app(SaveMember::class)($ana, [
        'full_name' => 'Ana Pérez',
        'spouse_id' => $luis->id,
        'children' => [],
        'roles' => [],
    ]);

    $own = ActivityLogEntry::query()->where('subject_id', $ana->id)->sole();
    $partner = ActivityLogEntry::query()->where('subject_id', $luis->id)->sole();

    expect($own->changes['spouse_id'])->toEqual(['from' => null, 'to' => $luis->id])
        ->and($partner->action)->toBe('member.spouse_linked')
        ->and($partner->summary)->toBe('Registró el vínculo matrimonial de Luis Gómez con Ana Pérez.');
});

test('sending a member to the trash releases and records the partner side', function () {
    $ana = Member::factory()->create(['full_name' => 'Ana Pérez']);
    $luis = Member::factory()->create(['full_name' => 'Luis Gómez']);
    $ana->forceFill(['spouse_id' => $luis->id])->save();
    $luis->forceFill(['spouse_id' => $ana->id])->save();

    app(DeleteMember::class)($ana);

    expect(ActivityLogEntry::query()->orderBy('seq')->pluck('action')->all())
        ->toBe(['member.spouse_released', 'member.trashed'])
        ->and(ActivityLogEntry::query()->where('action', 'member.spouse_released')->sole()->subject_id)->toBe($luis->id);
});

test('restoring a member is recorded', function () {
    $member = Member::factory()->create(['full_name' => 'Ana Pérez']);
    $member->delete();

    app(RestoreMember::class)($member);

    expect(ActivityLogEntry::query()->sole())
        ->action->toBe('member.restored')
        ->summary->toBe('Restauró al miembro Ana Pérez.');
});

test('a write that fails leaves no entry in the log', function () {
    $taken = Member::factory()->create(['document_type' => 'V', 'document_id' => '12345678']);

    expect(fn () => app(SaveMember::class)(null, [
        'full_name' => 'Otra Persona',
        'document_type' => 'V',
        'document_id' => $taken->document_id,
        'children' => [],
        'roles' => [],
    ]))->toThrow(ValidationException::class);

    expect(ActivityLogEntry::query()->count())->toBe(0);
});
