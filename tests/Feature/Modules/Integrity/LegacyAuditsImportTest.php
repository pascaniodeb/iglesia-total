<?php

use App\Models\User;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Audit\EvaluateAudit;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 14:00:00');
    createLegacySource();
    createLegacyAuditTables();
    $this->pastor = User::factory()->create(['legacy_id' => 1]);
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

/**
 * The fingerprint the old system computed for a finding: the same formula the new checks use.
 */
function legacyFingerprint(string $check, string $subject, string $detail): string
{
    return hash('sha256', "{$check}\x00{$subject}\x00{$detail}");
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyAcknowledgement(int $id, string $check, string $subject, string $detail, array $attributes = []): void
{
    DB::connection('legacy')->table('audit_acknowledgements')->insert([
        'id' => $id,
        'fingerprint' => legacyFingerprint($check, $subject, $detail),
        'check_id' => $check,
        'subject' => $subject,
        'detail' => $detail,
        'reason' => 'Migración correcta',
        'acknowledged_by' => 1,
        'acknowledged_at' => '2026-08-22 10:56:31.650782+00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyAudit(int $id, array $attributes = []): void
{
    DB::connection('legacy')->table('audits')->insert([
        'id' => $id,
        'ran_at' => '2026-08-22 11:00:54+00',
        'ran_by' => 1,
        'status' => 'findings',
        'findings_count' => 6,
        'critical_count' => 0,
        'snapshot' => json_encode(['checks' => [
            ['id' => 'offering-after-close', 'label' => 'Ofrendas tocadas después del cierre', 'description' => 'x', 'severity' => 'warning', 'findings' => [['subject' => '2025-W02 · ofrenda #11', 'detail' => 'La semana cerró el 03/01/2026.']]],
            ['id' => 'month-straddling-week', 'label' => 'Semanas que cruzan de mes', 'description' => 'y', 'severity' => 'info', 'findings' => [['subject' => '2025-W01', 'detail' => 'Va del 30/12 – 05/01/2025, así que sus ofrendas caen en dos meses distintos.']]],
        ]]),
        'notes' => 'Auditoría inicial.',
        ...$attributes,
    ]);
}

test('the acknowledgements and audits of the old system are imported with their author, reason and snapshot', function () {
    legacyAcknowledgement(7, 'offering-after-close', '2025-W02 · ofrenda #11', 'La semana cerró el 03/01/2026.');
    legacyAcknowledgement(13, 'month-straddling-week', '2025-W01', 'Va del 30/12 – 05/01/2025, así que sus ofrendas caen en dos meses distintos.');
    legacyAudit(5);

    $this->artisan('audits:import-legacy')->assertSuccessful();

    $ack = AuditAcknowledgement::query()->where('legacy_id', 13)->sole();
    $audit = Audit::query()->where('legacy_id', 5)->sole();

    expect(AuditAcknowledgement::query()->count())->toBe(2)
        ->and($ack->reason)->toBe('Migración correcta')
        ->and(DB::table('audit_acknowledgements')->where('legacy_id', 13)->value('acknowledged_at'))->toStartWith('2026-08-22 10:56:31.650782')
        ->and($ack->acknowledged_by)->toBe($this->pastor->id)
        ->and(trim($ack->fingerprint))->toBe(legacyFingerprint('month-straddling-week', '2025-W01', 'Va del 30/12 – 05/01/2025, así que sus ofrendas caen en dos meses distintos.'))
        ->and($audit->status)->toBe('findings')
        ->and($audit->findings_count)->toBe(6)
        ->and($audit->notes)->toBe('Auditoría inicial.')
        ->and($audit->ran_by)->toBe($this->pastor->id)
        ->and($audit->snapshot['checks'])->toHaveCount(2)
        // The finding an acknowledgement covered carries who accepted it and why, as it stood.
        ->and($audit->snapshot['checks'][1]['findings'][0]['acknowledgement']['reason'])->toBe('Migración correcta')
        ->and($audit->snapshot['checks'][1]['acknowledged'])->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'audits.imported_legacy')->count())->toBe(1);
});

test('it is idempotent: a second run writes nothing and logs nothing', function () {
    legacyAcknowledgement(7, 'offering-after-close', '2025-W02 · ofrenda #11', 'La semana cerró el 03/01/2026.');
    legacyAudit(5);

    $this->artisan('audits:import-legacy')->assertSuccessful();
    $this->artisan('audits:import-legacy')->assertSuccessful();

    expect(AuditAcknowledgement::query()->count())->toBe(1)
        ->and(Audit::query()->count())->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'audits.imported_legacy')->count())->toBe(1);
});

test('a revoked acknowledgement comes with its revocation, as a new row', function () {
    legacyAcknowledgement(7, 'offering-after-close', '2025-W02 · ofrenda #11', 'x', ['revoked_at' => '2026-08-23 09:00:00+00', 'revoked_by' => 1, 'revoke_reason' => 'Ya no aplica.']);

    $this->artisan('audits:import-legacy')->assertSuccessful();

    $ack = AuditAcknowledgement::query()->sole();
    expect($ack->revocation->reason)->toBe('Ya no aplica.')
        ->and($ack->revocation->revoked_by)->toBe($this->pastor->id);
});

test('an acknowledgement of a finding that still exists applies again by itself', function () {
    $week = IsoWeek::fromKey('2026-W44');
    $category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
    Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $category->id]);
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    Carbon::setTestNow('2026-11-06 14:00:00');
    app(CloseWeek::class)($week);

    legacyAcknowledgement(13, 'month-straddling-week', '2026-W44', 'Va del 26/10 – 01/11/2026, así que sus ofrendas caen en dos meses distintos.');
    $this->artisan('audits:import-legacy')->assertSuccessful();

    $straddling = collect(app(EvaluateAudit::class)()['checks'])->firstWhere('id', 'month-straddling-week');

    expect($straddling['findings'][0]['acknowledgement']['reason'])->toBe('Migración correcta');
});

test('an acknowledgement of a check that no longer exists is kept as history and reported', function () {
    legacyAcknowledgement(7, 'offering-after-close', '2025-W02 · ofrenda #11', 'La semana cerró el 03/01/2026.');

    $this->artisan('audits:import-legacy', ['--dry-run' => true])
        ->expectsOutputToContain('offering-after-close')
        ->assertSuccessful();

    expect(AuditAcknowledgement::query()->count())->toBe(0);
});

test('history is never rewritten: an imported acknowledgement that differs from the old one refuses the run', function () {
    legacyAcknowledgement(7, 'offering-after-close', '2025-W02 · ofrenda #11', 'x');
    $this->artisan('audits:import-legacy')->assertSuccessful();

    DB::connection('legacy')->table('audit_acknowledgements')->where('id', 7)->update(['reason' => 'Otro motivo distinto.']);

    $this->artisan('audits:import-legacy')->assertFailed();
});

test('an audit in the old trash is not imported', function () {
    legacyAudit(5, ['deleted_at' => '2026-08-23 00:00:00+00']);
    legacyAudit(6, ['status' => 'clean', 'findings_count' => 0]);

    $this->artisan('audits:import-legacy')->assertSuccessful();

    expect(Audit::query()->pluck('legacy_id')->all())->toBe([6]);
});

test('an acknowledgement without a valid fingerprint or a reason is refused before writing anything', function () {
    legacyAcknowledgement(7, 'offering-after-close', 's', 'd');
    legacyAcknowledgement(8, 'offering-after-close', 's2', 'd2', ['reason' => '  ']);

    $this->artisan('audits:import-legacy')->assertFailed();

    expect(AuditAcknowledgement::query()->count())->toBe(0);
});
