<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Audit\EvaluateAudit;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 14:00:00');
    signInAs(UserRole::Comptroller);
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');

    $category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
    Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $category->id, 'amount_cop' => '100.00']);
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function screenTamper(string $cop = '999.00'): void
{
    DB::statement('ALTER TABLE weekly_closures DISABLE TRIGGER weekly_closures_no_update');
    DB::statement("UPDATE weekly_closures SET total_cop = '{$cop}' WHERE week_key = '2026-W44'");
    DB::statement('ALTER TABLE weekly_closures ENABLE TRIGGER weekly_closures_no_update');
}

function screenFingerprint(string $check = 'weekly-closure-total'): string
{
    return collect(app(EvaluateAudit::class)()['checks'])->firstWhere('id', $check)['findings'][0]['fingerprint'];
}

test('the page shows the live findings, the audits and the acceptances', function () {
    screenTamper();

    $this->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page
        ->component('audits/index')
        ->where('evaluation.pending_count', 2)
        ->where('evaluation.critical_count', 2)
        ->where('days_since_last_run', null)
        ->where('can_record', true)
        ->has('evaluation.checks', 8)
        ->has('audits', 0)
        ->has('acknowledgements', 0));
});

test('running an audit stores it and lands on it', function () {
    screenTamper();

    $response = $this->post(route('audits.store'), ['notes' => 'Revisión de noviembre']);

    $audit = Audit::query()->sole();
    $response->assertRedirect(route('audits.show', $audit));
    expect($audit->notes)->toBe('Revisión de noviembre')->and($audit->status)->toBe('findings');

    $this->get(route('audits.show', $audit))->assertInertia(fn (Assert $page) => $page
        ->component('audits/show')
        ->where('audit.findings_count', 2)
        ->where('audit.notes', 'Revisión de noviembre')
        ->where('audit.checks', fn ($checks) => count($checks) === 8));
    $this->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page->has('audits', 1)->where('days_since_last_run', 0));
});

test('a finding is accepted with a reason and lifted with another', function () {
    screenTamper();

    $this->post(route('audits.acknowledge'), ['fingerprint' => screenFingerprint(), 'reason' => 'Revisado con el tesorero: error de digitación.'])->assertRedirect();
    $ack = AuditAcknowledgement::query()->sole();

    $this->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page
        ->where('evaluation.acknowledged_count', 1)
        ->where('acknowledgements.0.reason', 'Revisado con el tesorero: error de digitación.')
        ->where('acknowledgements.0.revoked', null));

    $this->post(route('audits.revoke', $ack), ['reason' => 'No era un error de digitación.'])->assertRedirect();

    $this->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page
        ->where('evaluation.acknowledged_count', 0)
        ->where('acknowledgements.0.revoked.reason', 'No era un error de digitación.'));
});

test('a reason that is too short or missing is refused with its message', function () {
    screenTamper();

    $this->post(route('audits.acknowledge'), ['fingerprint' => screenFingerprint(), 'reason' => 'ok'])->assertSessionHasErrors('reason');
    $this->post(route('audits.acknowledge'), ['fingerprint' => screenFingerprint()])->assertSessionHasErrors('reason');
    $this->post(route('audits.acknowledge'), ['fingerprint' => 'short', 'reason' => 'Un motivo suficientemente largo.'])->assertSessionHasErrors('fingerprint');

    expect(AuditAcknowledgement::query()->count())->toBe(0);
});

test('a finding that no longer exists cannot be accepted', function () {
    $this->post(route('audits.acknowledge'), ['fingerprint' => str_repeat('a', 64), 'reason' => 'Un motivo suficientemente largo.'])->assertSessionHasErrors('fingerprint');
});

test('the comptroller and the pastor audit; whoever is audited does not', function () {
    foreach ([UserRole::Treasurer, UserRole::Secretary, UserRole::Member] as $role) {
        signInAs($role);
        $this->get(route('audits.index'))->assertForbidden();
        $this->post(route('audits.store'))->assertForbidden();
    }

    signInAs(UserRole::Pastor);
    $this->get(route('audits.index'))->assertOk();
    $this->post(route('audits.store'))->assertRedirect();
});

test('a role that may only read sees the page but cannot act', function () {
    $reader = collect(UserRole::cases())->first(fn (UserRole $role) => $role->grants(Permission::AuditsView) && ! $role->grants(Permission::AuditsRecord) && $role !== UserRole::Pastor);

    if ($reader === null) {
        expect(true)->toBeTrue();

        return;
    }

    signInAs($reader);
    $this->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page->where('can_record', false));
    $this->post(route('audits.store'))->assertForbidden();
});
