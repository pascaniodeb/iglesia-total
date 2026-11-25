<?php

use App\Models\User;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createLegacySource();
    createLegacyTreasuryTables();

    $this->member = Member::factory()->create(['legacy_id' => 10, 'full_name' => 'Ana Pérez']);
    $this->daily = OfferingCategory::factory()->create(['legacy_id' => 1, 'name' => 'OFRENDA DIARIA']);
    $this->special = OfferingCategory::factory()->create(['legacy_id' => 2, 'name' => 'OFRENDA ESPECIAL']);
    $this->author = User::factory()->create(['legacy_id' => 5]);
});

afterEach(function () {
    dropLegacySource();
});

test('the live offerings come with their giver, category, author and dates; the trash does not', function () {
    legacyOffering(1, ['member_id' => 10, 'concept' => 'Culto', 'amount_ves' => '250.50', 'created_by' => 5, 'created_at' => '2025-01-06 15:00:00+00', 'updated_at' => '2025-01-07 09:30:00+00']);
    legacyOffering(2, ['date' => '2025-12-14', 'member_id' => 10, 'offering_category_id' => 2, 'amount_cop' => '237000.00', 'amount_usd' => '5.00']);
    legacyOffering(3, ['date' => '2025-02-02', 'member_id' => 10, 'deleted_at' => '2025-02-03 00:00:00+00']);

    $this->artisan('treasury:import-legacy-offerings')
        ->expectsOutputToContain('Skipped (in the old trash): #3')
        ->assertSuccessful();

    $first = Offering::query()->where('legacy_id', 1)->sole();

    expect(Offering::query()->withTrashed()->count())->toBe(2)
        ->and($first->member_id)->toBe($this->member->id)
        ->and($first->offering_category_id)->toBe($this->daily->id)
        ->and($first->created_by)->toBe($this->author->id)
        ->and($first->concept)->toBe('Culto')
        ->and([$first->amount_ves, $first->amount_usd, $first->amount_cop])->toBe(['250.50', '0.00', '1000.00'])
        ->and($first->created_at->format('Y-m-d H:i'))->toBe('2025-01-06 15:00')
        ->and($first->updated_at->format('Y-m-d H:i'))->toBe('2025-01-07 09:30')
        ->and($first->week_key)->toBe('2025-W01')
        ->and(Offering::query()->where('legacy_id', 2)->sole()->offering_category_id)->toBe($this->special->id);

    $log = ActivityLogEntry::query()->where('action', 'offerings.imported_legacy')->sole();

    expect($log->changes['skipped_in_trash'])->toBe([3])
        ->and($log->changes['totals'])->toEqual(['ves' => '250.50', 'usd' => '5.00', 'cop' => '238000.00']);
});

test('an author of the old system that was not imported leaves the offering without author', function () {
    legacyOffering(1, ['member_id' => 10, 'created_by' => 77]);

    $this->artisan('treasury:import-legacy-offerings')->expectsOutputToContain('1 (left without author)')->assertSuccessful();

    expect(Offering::query()->sole()->created_by)->toBeNull();
});

test('running it again changes nothing', function () {
    legacyOffering(1, ['member_id' => 10]);

    $this->artisan('treasury:import-legacy-offerings')->assertSuccessful();
    $this->artisan('treasury:import-legacy-offerings')->expectsOutputToContain('Already imported')->assertSuccessful();

    expect(Offering::query()->count())->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'offerings.imported_legacy')->count())->toBe(1);
});

test('an offering already imported that now differs from the old system is a conflict', function () {
    legacyOffering(1, ['member_id' => 10, 'amount_cop' => '1000.00']);
    $this->artisan('treasury:import-legacy-offerings')->assertSuccessful();

    DB::connection('legacy')->table('offerings')->where('id', 1)->update(['amount_cop' => '1500.00']);
    legacyOffering(2, ['member_id' => 10]);

    $this->artisan('treasury:import-legacy-offerings')->expectsOutputToContain('differ from the old system')->assertFailed();

    expect(Offering::query()->count())->toBe(1);
});

test('nothing is written when an offering points to a member or a category that was not imported', function () {
    legacyOffering(1, ['member_id' => 10]);
    legacyOffering(2, ['member_id' => 99]);
    legacyOffering(3, ['member_id' => 10, 'offering_category_id' => 9]);

    $this->artisan('treasury:import-legacy-offerings')
        ->expectsOutputToContain('member 99')
        ->expectsOutputToContain('category 9')
        ->assertFailed();

    expect(Offering::query()->count())->toBe(0);
});

test('a dry run reports and writes nothing', function () {
    legacyOffering(1, ['member_id' => 10]);

    $this->artisan('treasury:import-legacy-offerings', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(Offering::query()->count())->toBe(0);
});

test('everything is undone unless the totals of both systems are equal to the cent', function () {
    legacyOffering(1, ['member_id' => 10, 'amount_cop' => '1000.00']);

    // An offering of the new system that the old one does not have: the totals cannot match.
    Offering::factory()->create(['legacy_id' => 500, 'offering_category_id' => $this->daily->id, 'amount_cop' => '77.00']);

    $this->artisan('treasury:import-legacy-offerings')->expectsOutputToContain('everything was undone')->assertFailed();

    expect(Offering::query()->where('legacy_id', 1)->exists())->toBeFalse()
        ->and(ActivityLogEntry::query()->where('action', 'offerings.imported_legacy')->count())->toBe(0);
});
