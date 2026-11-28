<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Audit\AcknowledgeFinding;
use App\Modules\Integrity\Application\Audit\EvaluateAudit;
use App\Modules\Integrity\Application\Audit\RevokeAcknowledgement;
use App\Modules\Integrity\Application\Audit\RunAudit;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Friday 6 November 2026: week 2026-W44 (26 Oct - 1 Nov) has ended and straddles October and November.
    Carbon::setTestNow('2026-11-06 14:00:00');
    signInAs(UserRole::Comptroller);
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');

    $this->category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function closedWeekWithOffering(string $date = '2026-10-28', string $cop = '100.00'): Offering
{
    $offering = Offering::factory()->create(['offering_date' => $date, 'offering_category_id' => test()->category->id, 'amount_cop' => $cop]);
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    return $offering;
}

function tamperWeeklyTotal(string $cop): void
{
    DB::statement('ALTER TABLE weekly_closures DISABLE TRIGGER weekly_closures_no_update');
    DB::statement("UPDATE weekly_closures SET total_cop = '{$cop}' WHERE week_key = '2026-W44'");
    DB::statement('ALTER TABLE weekly_closures ENABLE TRIGGER weekly_closures_no_update');
}

/**
 * @return array<string, mixed>
 */
function auditCheck(string $id): array
{
    return collect(app(EvaluateAudit::class)()['checks'])->firstWhere('id', $id);
}

/**
 * Acknowledges every pending finding that counts, the way a comptroller would after reviewing.
 */
function acknowledgeAllPending(): void
{
    foreach (app(EvaluateAudit::class)()['checks'] as $check) {
        foreach ($check['findings'] as $finding) {
            if ($check['severity'] !== 'info' && $finding['acknowledgement'] === null) {
                app(AcknowledgeFinding::class)($finding['fingerprint'], 'Revisado con el tesorero: error de digitación.');
            }
        }
    }
}

test('every module registers its checks', function () {
    $ids = collect(app(EvaluateAudit::class)()['checks'])->pluck('id')->sort()->values()->all();

    expect($ids)->toBe([
        'carry-forward-chain', 'month-straddling-week', 'monthly-closure-total', 'sealed-ledger-chain',
        'sealed-ledger-drift', 'sealed-ledger-pending', 'week-without-closure', 'weekly-closure-total',
    ]);
});

test('clean books have no pending findings that count', function () {
    closedWeekWithOffering();

    $evaluation = app(EvaluateAudit::class)();

    expect($evaluation['pending_count'])->toBe(0)
        ->and($evaluation['critical_count'])->toBe(0)
        // The week straddles two months: informative, never counted.
        ->and(auditCheck('month-straddling-week')['findings'])->toHaveCount(1);
});

test('a closure that no longer holds its offerings is a critical finding that names the week', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');

    $check = auditCheck('weekly-closure-total');
    $evaluation = app(EvaluateAudit::class)();

    expect($check['severity'])->toBe('critical')
        ->and($check['findings'])->toHaveCount(1)
        ->and($check['findings'][0]['subject'])->toBe('2026-W44')
        ->and($check['findings'][0]['detail'])->toContain('999,00')
        // The closure total and the seal that no longer matches: both are the same incident, seen twice.
        ->and($evaluation['pending_count'])->toBe(2)
        ->and($evaluation['critical_count'])->toBe(2);
});

test('a week that ended with offerings and no closure is a warning', function () {
    Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $this->category->id]);

    $check = auditCheck('week-without-closure');

    expect($check['severity'])->toBe('warning')
        ->and($check['findings'])->toHaveCount(1)
        ->and($check['findings'][0]['subject'])->toBe('2026-W44');
});

test('the audit keeps the act: who ran it, the notes and the findings as they stood', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');

    $audit = app(RunAudit::class)('  Revisión mensual  ');

    expect($audit->status)->toBe('findings')
        ->and($audit->findings_count)->toBe(2)
        ->and($audit->critical_count)->toBe(2)
        ->and($audit->notes)->toBe('Revisión mensual')
        ->and($audit->ran_by)->not->toBeNull()
        ->and(collect($audit->snapshot['checks'])->firstWhere('id', 'weekly-closure-total')['findings'][0]['subject'])->toBe('2026-W44')
        ->and(ActivityLogEntry::query()->where('action', 'audit.ran')->count())->toBe(1);
});

test('a clean audit is clean', function () {
    closedWeekWithOffering();

    expect(app(RunAudit::class)()->status)->toBe('clean');
});

test('an acknowledged finding stops counting and the audit says so', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');

    acknowledgeAllPending();
    $audit = app(RunAudit::class)();
    $ack = AuditAcknowledgement::query()->where('check_id', 'weekly-closure-total')->sole();

    expect($ack->subject)->toBe('2026-W44')
        ->and($audit->status)->toBe('clean')
        ->and($audit->findings_count)->toBe(0)
        ->and($audit->acknowledged_count)->toBe(2)
        ->and(auditCheck('weekly-closure-total')['findings'][0]['acknowledgement']['reason'])->toBe('Revisado con el tesorero: error de digitación.');
});

test('an acknowledgement stops applying by itself when the data change', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');
    acknowledgeAllPending();

    tamperWeeklyTotal('555.00');

    expect(auditCheck('weekly-closure-total')['findings'][0]['acknowledgement'])->toBeNull()
        ->and(app(RunAudit::class)()->status)->toBe('findings');
});

test('an acknowledgement needs a reason and a finding that exists now', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');
    $fingerprint = auditCheck('weekly-closure-total')['findings'][0]['fingerprint'];

    expect(fn () => app(AcknowledgeFinding::class)($fingerprint, 'ok'))->toThrow(ValidationException::class)
        ->and(fn () => app(AcknowledgeFinding::class)(str_repeat('a', 64), 'Un motivo suficientemente largo.'))->toThrow(ValidationException::class);

    app(AcknowledgeFinding::class)($fingerprint, 'Un motivo suficientemente largo.');

    expect(fn () => app(AcknowledgeFinding::class)($fingerprint, 'Otro motivo suficientemente largo.'))->toThrow(ValidationException::class);
});

test('revoking is a new row, brings the finding back and cannot be done twice', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');
    $ack = app(AcknowledgeFinding::class)(auditCheck('weekly-closure-total')['findings'][0]['fingerprint'], 'Revisado con el tesorero: error de digitación.');

    app(RevokeAcknowledgement::class)($ack, 'Se encontró que no era un error de digitación.');

    expect(auditCheck('weekly-closure-total')['findings'][0]['acknowledgement'])->toBeNull()
        ->and(AuditAcknowledgement::query()->count())->toBe(1)
        ->and($ack->revocation()->exists())->toBeTrue()
        ->and(fn () => app(RevokeAcknowledgement::class)($ack->fresh(), 'Otra vez, con un motivo largo.'))->toThrow(ValidationException::class)
        ->and(fn () => app(RevokeAcknowledgement::class)(AuditAcknowledgement::query()->first(), 'x'))->toThrow(ValidationException::class);
});

test('audits, acknowledgements and revocations are append-only', function () {
    closedWeekWithOffering();
    tamperWeeklyTotal('999.00');
    $audit = app(RunAudit::class)();
    $ack = app(AcknowledgeFinding::class)(auditCheck('weekly-closure-total')['findings'][0]['fingerprint'], 'Revisado con el tesorero: error de digitación.');

    expect(fn () => DB::transaction(fn () => DB::table('audits')->where('id', $audit->id)->update(['notes' => 'changed'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audits')->delete()))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_acknowledgements')->where('id', $ack->id)->update(['reason' => 'changed'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_acknowledgements')->delete()))->toThrow(QueryException::class, 'append-only');
    expect(Audit::query()->count())->toBe(1);
});

test('a blank reason cannot be written even around the application', function () {
    expect(fn () => DB::transaction(fn () => DB::table('audit_acknowledgements')->insert([
        'fingerprint' => str_repeat('a', 64), 'check_id' => 'x', 'subject' => 's', 'detail' => 'd', 'reason' => '   ',
    ])))->toThrow(QueryException::class, 'reason_not_blank');
});

test('the fingerprint is the old system\'s formula, so its acknowledgements still apply', function () {
    closedWeekWithOffering();

    $finding = auditCheck('month-straddling-week')['findings'][0];

    expect($finding['subject'])->toBe('2026-W44')
        ->and($finding['detail'])->toBe('Va del 26/10 – 01/11/2026, así que sus ofrendas caen en dos meses distintos.')
        ->and($finding['fingerprint'])->toBe(hash('sha256', "month-straddling-week\x002026-W44\x00Va del 26/10 – 01/11/2026, así que sus ofrendas caen en dos meses distintos."));
});
