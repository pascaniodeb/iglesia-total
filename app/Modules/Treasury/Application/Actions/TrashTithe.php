<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Support\Facades\DB;

/**
 * Sends a tithe recorded by mistake to the trash, or brings it back. It is never physically
 * deleted; both movements are in the activity log.
 */
final readonly class TrashTithe
{
    public function __construct(private MemberDirectory $members, private ActivityLog $activityLog) {}

    public function trash(Tithe $tithe): void
    {
        DB::transaction(function () use ($tithe): void {
            $tithe->delete();

            $this->activityLog->record(
                'tithe.trashed',
                __('Sent to the trash a tithe of :member on :date.', $this->context($tithe)),
                $tithe,
                ['amount_ves' => $tithe->amount_ves, 'amount_usd' => $tithe->amount_usd, 'amount_cop' => $tithe->amount_cop],
            );
        });
    }

    public function restore(Tithe $tithe): void
    {
        DB::transaction(function () use ($tithe): void {
            $tithe->restore();

            $this->activityLog->record(
                'tithe.restored',
                __('Restored a tithe of :member on :date.', $this->context($tithe)),
                $tithe,
            );
        });
    }

    /**
     * @return array{member: string, date: string}
     */
    private function context(Tithe $tithe): array
    {
        return [
            'member' => $this->members->find($tithe->member_id)->name ?? '',
            'date' => $tithe->tithe_date->format('d/m/Y'),
        ];
    }
}
