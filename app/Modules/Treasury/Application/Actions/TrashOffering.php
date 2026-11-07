<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends an offering recorded by mistake to the trash. It is never physically deleted: the row
 * and its record in the activity log stay.
 */
final readonly class TrashOffering
{
    public function __construct(private MemberDirectory $members, private ActivityLog $activityLog) {}

    /**
     * @throws ValidationException when the offering's week is closed
     */
    public function __invoke(Offering $offering): void
    {
        $weekKey = IsoWeek::containing($offering->offering_date->toDateTimeImmutable())->key();

        if (WeeklyClosure::isClosed($weekKey)) {
            throw ValidationException::withMessages(['offering' => __('The week :week is closed: its offerings can no longer change.', ['week' => $weekKey])]);
        }

        DB::transaction(function () use ($offering): void {
            $offering->delete();

            $giver = $offering->member_id === null ? null : $this->members->find($offering->member_id);

            $this->activityLog->record(
                'offering.trashed',
                __('Sent to the trash an offering of :giver on :date.', [
                    'giver' => $giver->name ?? __('anonymous'),
                    'date' => $offering->offering_date->format('d/m/Y'),
                ]),
                $offering,
                [
                    'amount_ves' => $offering->amount_ves,
                    'amount_usd' => $offering->amount_usd,
                    'amount_cop' => $offering->amount_cop,
                ],
            );
        });
    }
}
