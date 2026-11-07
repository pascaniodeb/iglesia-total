<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Accounting\Infrastructure\Models\JournalEntrySupport;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    Storage::fake('local');
    signInAs(UserRole::Treasurer);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supportedMovement(array $overrides = []): array
{
    return [
        'kind' => 'income',
        'account_code' => 'I-007',
        'currency' => 'COP',
        'amount' => '1000',
        'entry_date' => '2026-11-10',
        'description' => 'Aporte',
        'token' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

function receiptPhoto(): UploadedFile
{
    $image = imagecreatetruecolor(40, 20);
    ob_start();
    imagejpeg($image);

    return UploadedFile::fake()->createWithContent('Recibo luz noviembre.jpg', (string) ob_get_clean());
}

test('a photo is stored privately as WebP with the fingerprint of the stored bytes', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['receipt_number' => 'F-00123', 'support' => receiptPhoto()]))
        ->assertSessionHasNoErrors();

    $support = JournalEntrySupport::query()->sole();
    $bytes = Storage::disk('local')->get((string) $support->file_path);

    expect($support->receipt_number)->toBe('F-00123')
        ->and($support->mime_type)->toBe('image/webp')
        ->and($support->file_name)->toBe('recibo-luz-noviembre.webp')
        ->and($support->file_path)->toStartWith('supports/2026/11/')
        ->and($support->sha256)->toBe(hash('sha256', (string) $bytes))
        ->and(substr((string) $bytes, 8, 4))->toBe('WEBP');
});

test('a PDF is kept as it is, and a receipt number alone is a valid support', function () {
    $pdf = UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\n%fake\n");

    $this->post(route('accounting.journal.store'), supportedMovement(['support' => $pdf]))->assertSessionHasNoErrors();
    $this->post(route('accounting.journal.store'), supportedMovement(['receipt_number' => '000456']))->assertSessionHasNoErrors();

    $supports = JournalEntrySupport::query()->orderBy('created_at')->get();

    expect($supports[0]->mime_type)->toBe('application/pdf')
        ->and(Storage::disk('local')->get((string) $supports[0]->file_path))->toBe("%PDF-1.4\n%fake\n")
        ->and($supports[1]->file_path)->toBeNull()
        ->and($supports[1]->receipt_number)->toBe('000456');
});

test('other file types are refused', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['support' => UploadedFile::fake()->create('virus.exe', 10)]))
        ->assertSessionHasErrors('support');

    expect(JournalEntry::query()->count())->toBe(0);
});

test('when the movement is refused, the uploaded file is not left behind', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['kind' => 'expense', 'account_code' => 'E-024', 'support' => receiptPhoto()]))
        ->assertSessionHasErrors('amount');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('opening the support checks its fingerprint and records the access', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['support' => receiptPhoto()]))->assertSessionHasNoErrors();
    $entry = JournalEntry::query()->sole();

    $this->get(route('accounting.journal.support', $entry))
        ->assertOk()
        ->assertHeader('content-type', 'image/webp');

    expect(ActivityLogEntry::query()->where('action', 'journal_support.opened')->count())->toBe(1);

    Storage::disk('local')->put((string) JournalEntrySupport::query()->sole()->file_path, 'cambiado');

    $this->get(route('accounting.journal.support', $entry))->assertStatus(409);
});

test('a correction keeps the original support unless a new one is attached', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['receipt_number' => 'R-1', 'support' => receiptPhoto()]))->assertSessionHasNoErrors();
    $original = JournalEntry::query()->sole();

    $this->post(route('accounting.journal.correct', $original), [
        'account_code' => 'I-007', 'currency' => 'COP', 'amount' => '1200', 'entry_date' => '2026-11-10',
        'description' => 'Aporte', 'token' => (string) Str::uuid7(), 'reason' => 'Eran 1.200',
    ])->assertSessionHasNoErrors();

    [$first, $second] = JournalEntrySupport::query()->orderBy('created_at')->get()->all();

    expect($second->file_path)->toBe($first->file_path)
        ->and($second->sha256)->toBe($first->sha256)
        ->and($second->receipt_number)->toBe('R-1');
});

test('the comptroller opens supports but cannot attach them; the secretary cannot open them', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['support' => receiptPhoto()]))->assertSessionHasNoErrors();
    $entry = JournalEntry::query()->sole();

    signInAs(UserRole::Comptroller);
    $this->get(route('accounting.journal.support', $entry))->assertOk();
    $this->post(route('accounting.journal.store'), supportedMovement(['support' => receiptPhoto()]))->assertForbidden();

    signInAs(UserRole::Secretary);
    $this->get(route('accounting.journal.support', $entry))->assertForbidden();
});

test('supports are append-only', function () {
    $this->post(route('accounting.journal.store'), supportedMovement(['receipt_number' => 'R-1']))->assertSessionHasNoErrors();

    expect(fn () => DB::transaction(fn () => DB::statement("UPDATE journal_entry_supports SET receipt_number = 'R-2'")))
        ->toThrow(QueryException::class, 'append-only');
});
