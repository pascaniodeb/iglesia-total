<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use Illuminate\Support\Facades\DB;

/**
 * Sends an offering recorded by mistake to the trash. It is never physically deleted: the row
 * and its record in the activity log stay.
 */
final readonly class TrashOffering
{
    public function __construct(private MemberDirectory $members, private ActivityLog $activityLog) {}

    public function __invoke(Offering $offering): void
    {
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
