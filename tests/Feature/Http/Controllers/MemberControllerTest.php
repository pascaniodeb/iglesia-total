<?php

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\MemberChild;
use App\Models\MemberRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function memberPayload(array $overrides = []): array
{
    return [
        'full_name' => 'María Fernández',
        'children' => [],
        'roles' => [],
        ...$overrides,
    ];
}

function signInAs(UserRole $role): User
{
    $user = User::factory()->role($role)->create();
    test()->actingAs($user);

    return $user;
}

dataset('roles that view members', [
    'pastor' => UserRole::Pastor,
    'assistant' => UserRole::Assistant,
    'secretary' => UserRole::Secretary,
    'comptroller' => UserRole::Comptroller,
]);

dataset('roles that cannot manage members', [
    'assistant' => UserRole::Assistant,
    'comptroller' => UserRole::Comptroller,
]);

dataset('roles without access to members', [
    'treasurer' => UserRole::Treasurer,
    'member' => UserRole::Member,
]);

describe('index', function () {
    test('lists the registry to the roles that can view members', function (UserRole $role) {
        signInAs($role);
        Member::factory()->create(['full_name' => 'Ana Pérez']);

        $response = $this->get(route('members.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/index')
            ->where('members.total', 1)
            ->where('members.data.0.full_name', 'Ana Pérez'));
    })->with('roles that view members');

    test('forbids the registry to the roles without the permission', function (UserRole $role) {
        signInAs($role);

        $this->get(route('members.index'))->assertForbidden();
    })->with('roles without access to members');

    test('sends guests to the login page', function () {
        $this->get(route('members.index'))->assertRedirect(route('login'));
    });

    test('orders the names ignoring accents', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['full_name' => 'Beatriz']);
        Member::factory()->create(['full_name' => 'Álvaro']);
        Member::factory()->create(['full_name' => 'Aaron']);

        $response = $this->get(route('members.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('members.data.0.full_name', 'Aaron')
            ->where('members.data.1.full_name', 'Álvaro')
            ->where('members.data.2.full_name', 'Beatriz'));
    });

    test('hides the trash by default and shows it on request', function (string $query, int $total) {
        signInAs(UserRole::Secretary);
        Member::factory()->create();
        Member::factory()->trashed()->create();

        $response = $this->get(route('members.index').$query);

        $response->assertInertia(fn (Assert $page) => $page->where('members.total', $total));
    })->with([
        'default is the active ones' => ['', 1],
        'trash only' => ['?status=inactive', 1],
        'active and trash' => ['?status=all', 2],
    ]);

    test('marks the members in the trash', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->trashed()->create();

        $response = $this->get(route('members.index', ['status' => 'inactive']));

        $response->assertInertia(fn (Assert $page) => $page->where('members.data.0.is_deleted', true));
    });

    test('searches names ignoring accents and case', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['full_name' => 'Ángela Núñez']);
        Member::factory()->create(['full_name' => 'Pedro Pérez']);

        $response = $this->get(route('members.index', ['search' => 'NUNEZ']));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.full_name', 'Ángela Núñez'));
    });

    test('searches by document and by phone', function (string $term, string $expected) {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['full_name' => 'Con Documento', 'document_id' => '12345678']);
        Member::factory()->create(['full_name' => 'Con Teléfono', 'phone' => '0414-5550000']);
        Member::factory()->create(['full_name' => 'Sin Nada']);

        $response = $this->get(route('members.index', ['search' => $term]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.full_name', $expected));
    })->with([
        'document' => ['12345678', 'Con Documento'],
        'phone' => ['5550000', 'Con Teléfono'],
    ]);

    test('treats wildcard characters in the search literally', function (string $term) {
        signInAs(UserRole::Secretary);
        Member::factory()->count(2)->create();

        $response = $this->get(route('members.index', ['search' => $term]));

        $response->assertInertia(fn (Assert $page) => $page->where('members.total', 0));
    })->with(['percent' => '%', 'underscore' => '_']);

    test('filters by member status and by sex', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['full_name' => 'Activa', 'status' => MemberStatus::Active, 'sex' => 'femenino']);
        Member::factory()->create(['full_name' => 'Trasladado', 'status' => MemberStatus::Transferred, 'sex' => 'masculino']);

        $byStatus = $this->get(route('members.index', ['member_status' => 'trasladado']));
        $bySex = $this->get(route('members.index', ['sex' => 'femenino']));

        $byStatus->assertInertia(fn (Assert $page) => $page->where('members.data.0.full_name', 'Trasladado')->where('members.total', 1));
        $bySex->assertInertia(fn (Assert $page) => $page->where('members.data.0.full_name', 'Activa')->where('members.total', 1));
    });

    test('falls back to the defaults for values it does not know', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->count(2)->create();

        $response = $this->get(route('members.index', [
            'sort' => 'password',
            'direction' => 'sideways',
            'per_page' => '999',
            'member_status' => 'nonsense',
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc')
            ->where('filters.per_page', 15)
            ->where('filters.member_status', 'all')
            ->where('members.total', 2));
    });

    test('pages the listing on the server', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->count(20)->create();

        $response = $this->get(route('members.index', ['page' => 2]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 20)
            ->where('members.last_page', 2)
            ->has('members.data', 5));
    });
});

describe('store', function () {
    test('shows the registration form to whoever manages members', function () {
        signInAs(UserRole::Secretary);

        $response = $this->get(route('members.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/create')
            ->has('options.document_types', 4)
            ->has('options.statuses', 4));
    });

    test('forbids registering to the roles that only view', function (UserRole $role) {
        signInAs($role);

        $this->get(route('members.create'))->assertForbidden();
        $this->post(route('members.store'), memberPayload())->assertForbidden();
        expect(Member::count())->toBe(0);
    })->with('roles that cannot manage members');

    test('registers a member with only the name', function () {
        signInAs(UserRole::Secretary);

        $response = $this->post(route('members.store'), memberPayload());

        $member = Member::firstOrFail();

        $response->assertRedirect(route('members.show', $member));
        expect($member->full_name)->toBe('María Fernández');
        expect($member->children)->toHaveCount(0);
    });

    test('registers the whole card with children, roles and spouse', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->create();

        $this->post(route('members.store'), memberPayload([
            'document_type' => 'V',
            'document_id' => '12345678',
            'sex' => 'femenino',
            'birth_date' => '1990-05-20',
            'marital_status' => 'casado',
            'status' => 'activo',
            'spouse_id' => $spouse->id,
            'children' => [
                ['name' => 'Sofía', 'birth_date' => '2018-01-02'],
                ['name' => 'Mateo', 'birth_date' => ''],
            ],
            'roles' => [['role' => 'Diaconisa', 'since' => '2024-01-01', 'until' => '']],
        ]));

        $member = Member::where('full_name', 'María Fernández')->firstOrFail();

        expect($member->document_id)->toBe('12345678');
        expect($member->birth_date->toDateString())->toBe('1990-05-20');
        expect($member->children->pluck('name')->all())->toBe(['Sofía', 'Mateo']);
        expect($member->roles->pluck('role')->all())->toBe(['Diaconisa']);
        expect($member->spouse_id)->toBe($spouse->id);
        expect($spouse->refresh()->spouse_id)->toBe($member->id);
    });

    test('flashes a success toast in Spanish', function () {
        signInAs(UserRole::Secretary);

        $response = $this->post(route('members.store'), memberPayload());

        $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Se registró al miembro María Fernández.']);
    });

    test('turns empty text into nothing recorded', function () {
        signInAs(UserRole::Secretary);

        $this->post(route('members.store'), memberPayload(['phone' => '', 'birth_date' => '']));

        expect(Member::firstOrFail()->phone)->toBeNull();
        expect(Member::firstOrFail()->birth_date)->toBeNull();
    });

    test('rejects a request without the name', function () {
        signInAs(UserRole::Secretary);

        $response = $this->post(route('members.store'), memberPayload(['full_name' => '']));

        $response->assertSessionHasErrors(['full_name' => 'El campo nombre completo es obligatorio.']);
        expect(Member::count())->toBe(0);
    });

    test('rejects a document that already belongs to another member', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['document_id' => '12345678']);

        $response = $this->post(route('members.store'), memberPayload(['document_id' => '12345678']));

        $response->assertSessionHasErrors(['document_id' => 'El valor del campo número de documento ya está en uso.']);
    });

    test('rejects values outside what the form offers', function (string $field, mixed $value) {
        signInAs(UserRole::Secretary);

        $response = $this->post(route('members.store'), memberPayload([$field => $value]));

        $response->assertSessionHasErrors($field);
    })->with([
        'unknown status' => ['status', 'desaparecido'],
        'unknown sex' => ['sex', 'otro'],
        'unknown document type' => ['document_type', 'cedula'],
        'future birth date' => ['birth_date', '2999-01-01'],
        'badly written date' => ['baptism_date', '20/05/2020'],
        'wrong email' => ['email', 'no-es-un-correo'],
    ]);

    test('rejects a child without a name and a role that ends before it starts', function () {
        signInAs(UserRole::Secretary);

        $response = $this->post(route('members.store'), memberPayload([
            'children' => [['name' => '', 'birth_date' => '']],
            'roles' => [['role' => 'Maestra', 'since' => '2024-06-01', 'until' => '2024-01-01']],
        ]));

        $response->assertSessionHasErrors(['children.0.name', 'roles.0.until']);
    });
});

describe('show', function () {
    test('shows the card with children, roles and spouse', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->create(['full_name' => 'Pedro Gómez']);
        $member = Member::factory()->create(['full_name' => 'Ana Gómez', 'spouse_id' => $spouse->id]);
        MemberChild::factory()->for($member)->create(['name' => 'Luis']);
        MemberRole::factory()->for($member)->create(['role' => 'Tesorera']);

        $response = $this->get(route('members.show', $member));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/edit')
            ->where('member.full_name', 'Ana Gómez')
            ->where('member.spouse.name', 'Pedro Gómez')
            ->where('member.children.0.name', 'Luis')
            ->where('member.roles.0.role', 'Tesorera')
            ->where('read_only', false));
    });

    test('shows the card read-only to the roles that only view', function (UserRole $role) {
        signInAs($role);
        $member = Member::factory()->create();

        $response = $this->get(route('members.show', $member));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('read_only', true));
    })->with('roles that cannot manage members');

    test('opens a member in the trash read-only', function () {
        signInAs(UserRole::Pastor);
        $member = Member::factory()->trashed()->create();

        $response = $this->get(route('members.show', $member));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('member.is_deleted', true)
            ->where('read_only', true));
    });

    test('answers not found for an unknown or malformed id', function (string $id) {
        signInAs(UserRole::Pastor);

        $this->get("/members/{$id}")->assertNotFound();
    })->with([
        'unknown uuid' => '0197a4b0-0000-7000-8000-000000000000',
        'not a uuid' => 'abc',
    ]);
});

describe('update', function () {
    test('updates the card and replaces children and roles as a whole', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->create(['full_name' => 'Nombre Viejo']);
        MemberChild::factory()->for($member)->count(2)->create();
        MemberRole::factory()->for($member)->create();

        $response = $this->put(route('members.update', $member), memberPayload([
            'full_name' => 'Nombre Nuevo',
            'children' => [['name' => 'Único Hijo', 'birth_date' => '2015-03-04']],
            'roles' => [],
        ]));

        $response->assertRedirect(route('members.show', $member));
        expect($member->refresh()->full_name)->toBe('Nombre Nuevo');
        expect($member->children->pluck('name')->all())->toBe(['Único Hijo']);
        expect($member->roles)->toHaveCount(0);
    });

    test('lets a member keep their own document', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->create(['document_id' => '12345678']);

        $response = $this->put(route('members.update', $member), memberPayload(['document_id' => '12345678']));

        $response->assertSessionHasNoErrors();
    });

    test('rejects the document of another member', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['document_id' => '11111111']);
        $member = Member::factory()->create(['document_id' => '22222222']);

        $response = $this->put(route('members.update', $member), memberPayload(['document_id' => '11111111']));

        $response->assertSessionHasErrors('document_id');
    });

    test('forbids updating to the roles that only view', function (UserRole $role) {
        signInAs($role);
        $member = Member::factory()->create(['full_name' => 'Intacto']);

        $this->put(route('members.update', $member), memberPayload(['full_name' => 'Cambiado']))->assertForbidden();
        expect($member->refresh()->full_name)->toBe('Intacto');
    })->with('roles that cannot manage members');

    test('answers not found when updating a member in the trash', function () {
        signInAs(UserRole::Pastor);
        $member = Member::factory()->trashed()->create();

        $this->put(route('members.update', $member), memberPayload())->assertNotFound();
    });
});

describe('spouse', function () {
    test('moves the marriage to another member and frees the previous spouse', function () {
        signInAs(UserRole::Secretary);
        $previous = Member::factory()->create();
        $next = Member::factory()->create();
        $member = Member::factory()->create(['spouse_id' => $previous->id]);
        $previous->update(['spouse_id' => $member->id]);

        $this->put(route('members.update', $member), memberPayload(['spouse_id' => $next->id]));

        expect($member->refresh()->spouse_id)->toBe($next->id);
        expect($next->refresh()->spouse_id)->toBe($member->id);
        expect($previous->refresh()->spouse_id)->toBeNull();
    });

    test('frees both sides when the spouse is removed', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->create();
        $member = Member::factory()->create(['spouse_id' => $spouse->id]);
        $spouse->update(['spouse_id' => $member->id]);

        $this->put(route('members.update', $member), memberPayload(['spouse_id' => '']));

        expect($member->refresh()->spouse_id)->toBeNull();
        expect($spouse->refresh()->spouse_id)->toBeNull();
    });

    test('rejects a member married to themselves', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->create();

        $response = $this->put(route('members.update', $member), memberPayload(['spouse_id' => $member->id]));

        $response->assertSessionHasErrors(['spouse_id' => 'Un miembro no puede figurar casado consigo mismo.']);
    });

    test('rejects a spouse that does not exist', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->create();

        $response = $this->put(route('members.update', $member), memberPayload(['spouse_id' => '0197a4b0-0000-7000-8000-000000000000']));

        $response->assertSessionHasErrors(['spouse_id' => 'Ese miembro ya no existe.']);
    });

    test('rejects a spouse in the trash', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->trashed()->create();
        $member = Member::factory()->create();

        $response = $this->put(route('members.update', $member), memberPayload(['spouse_id' => $spouse->id]));

        $response->assertSessionHasErrors(['spouse_id' => 'Ese miembro está en la papelera.']);
    });

    test('rejects a spouse who is already married to someone else', function () {
        signInAs(UserRole::Secretary);
        $other = Member::factory()->create();
        $taken = Member::factory()->create(['full_name' => 'Carlos Ocupado', 'spouse_id' => $other->id]);
        $other->update(['spouse_id' => $taken->id]);
        $member = Member::factory()->create();

        $response = $this->put(route('members.update', $member), memberPayload(['spouse_id' => $taken->id]));

        $response->assertSessionHasErrors(['spouse_id' => '«Carlos Ocupado» ya figura casado con otra persona. Corrija esa ficha primero.']);
        expect($taken->refresh()->spouse_id)->toBe($other->id);
    });

    test('saves nothing when the spouse is rejected', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->create(['full_name' => 'Nombre Viejo']);
        MemberChild::factory()->for($member)->create(['name' => 'Hijo Viejo']);

        $this->put(route('members.update', $member), memberPayload([
            'full_name' => 'Nombre Nuevo',
            'children' => [['name' => 'Hijo Nuevo', 'birth_date' => '']],
            'spouse_id' => $member->id,
        ]));

        expect($member->refresh()->full_name)->toBe('Nombre Viejo');
        expect($member->children->pluck('name')->all())->toBe(['Hijo Viejo']);
    });
});

describe('trash', function () {
    test('sends a member to the trash and frees the spouse on both sides', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->create();
        $member = Member::factory()->create(['spouse_id' => $spouse->id]);
        $spouse->update(['spouse_id' => $member->id]);

        $response = $this->delete(route('members.destroy', $member));

        $response->assertRedirect(route('members.index'));
        $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "El miembro {$member->full_name} se envió a la papelera."]);
        expect($member->refresh()->trashed())->toBeTrue();
        expect($member->spouse_id)->toBeNull();
        expect($spouse->refresh()->spouse_id)->toBeNull();
    });

    test('does not delete a member who holds a user account', function () {
        signInAs(UserRole::Pastor);
        $member = Member::factory()->create();
        User::factory()->forMember($member)->create();

        $response = $this->delete(route('members.destroy', $member));

        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Este miembro tiene una cuenta de usuario y no puede eliminarse.']);
        expect($member->refresh()->trashed())->toBeFalse();
    });

    test('restores a member from the trash without restoring the marriage', function () {
        signInAs(UserRole::Secretary);
        $member = Member::factory()->trashed()->create();

        $response = $this->post(route('members.restore', $member));

        $response->assertRedirect(route('members.show', $member));
        expect($member->refresh()->trashed())->toBeFalse();
        expect($member->spouse_id)->toBeNull();
    });

    test('forbids the trash to the roles that only view', function (UserRole $role) {
        signInAs($role);
        $member = Member::factory()->create();
        $trashed = Member::factory()->trashed()->create();

        $this->delete(route('members.destroy', $member))->assertForbidden();
        $this->post(route('members.restore', $trashed))->assertForbidden();
        expect($member->refresh()->trashed())->toBeFalse();
        expect($trashed->refresh()->trashed())->toBeTrue();
    })->with('roles that cannot manage members');
});

describe('spouse options', function () {
    test('offers the free members that match the search', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->create(['full_name' => 'Rosa Libre']);
        Member::factory()->create(['full_name' => 'Rosario Libre']);
        Member::factory()->create(['full_name' => 'Pedro Libre']);

        $response = $this->getJson(route('members.spouse-options', ['search' => 'rosa']));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Rosa Libre');
    });

    test('leaves out the member being edited, the married and the ones in the trash', function () {
        signInAs(UserRole::Secretary);
        $editing = Member::factory()->create(['full_name' => 'Yo Mismo']);
        $other = Member::factory()->create();
        Member::factory()->create(['full_name' => 'Casada', 'spouse_id' => $other->id]);
        Member::factory()->trashed()->create(['full_name' => 'Borrada']);
        Member::factory()->create(['full_name' => 'Disponible']);

        $response = $this->getJson(route('members.spouse-options', ['except' => $editing->id]));

        $names = collect($response->json('data'))->pluck('name')->all();

        expect($names)->toContain('Disponible', $other->full_name);
        expect($names)->not->toContain('Yo Mismo', 'Casada', 'Borrada');
    });

    test('keeps the current spouse of the member being edited among the options', function () {
        signInAs(UserRole::Secretary);
        $spouse = Member::factory()->create(['full_name' => 'Actual Pareja']);
        $member = Member::factory()->create(['spouse_id' => $spouse->id]);
        $spouse->update(['spouse_id' => $member->id]);

        $response = $this->getJson(route('members.spouse-options', ['except' => $member->id]));

        expect(collect($response->json('data'))->pluck('name')->all())->toContain('Actual Pareja');
    });

    test('returns at most fifteen options', function () {
        signInAs(UserRole::Secretary);
        Member::factory()->count(20)->create();

        $response = $this->getJson(route('members.spouse-options'));

        $response->assertJsonCount(15, 'data');
    });

    test('forbids the roles that only view', function (UserRole $role) {
        signInAs($role);

        $this->getJson(route('members.spouse-options'))->assertForbidden();
    })->with('roles that cannot manage members');
});
