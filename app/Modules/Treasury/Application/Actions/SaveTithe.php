<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Domain\CurrencyAmounts;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records or corrects a tithe.
 *
 * The date is never in the future (church calendar), the member must be one of the registry (the
 * member a tithe already has may stay even if they were trashed later) and at least one amount is
 * positive. Nothing closes a tithe: it is not income of the church, so it never reaches the books.
 * Every change is recorded in the activity log in the same transaction.
 */
final readonly class SaveTithe
{
    private const array LOGGED = ['tithe_date', 'member_id', 'amount_ves', 'amount_usd', 'amount_cop', 'notes'];

    public function __construct(
        private MemberDirectory $members,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @param  array{tithe_date: string, member_id: string, notes?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(?Tithe $tithe, array $data): Tithe
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['tithe_date']);

        if ($date === false || $date->format('Y-m-d') !== $data['tithe_date']) {
            throw ValidationException::withMessages(['tithe_date' => __('Choose a valid date.')]);
        }

        if ($date > ChurchCalendar::today()) {
            throw ValidationException::withMessages(['tithe_date' => __('The tithe date cannot be in the future.')]);
        }

        $member = $this->members->find($data['member_id']);
        $keepsMember = $tithe !== null && $tithe->member_id === $data['member_id'];

        if ($member === null || (! $member->isInRegistry && ! $keepsMember)) {
            throw ValidationException::withMessages(['member_id' => __('Choose a member of the registry.')]);
        }

        try {
            $amounts = CurrencyAmounts::fromInput($data['amount_ves'] ?? null, $data['amount_usd'] ?? null, $data['amount_cop'] ?? null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount_ves' => __('Write at least one amount greater than zero, with up to two decimals and no thousands separators.')]);
        }

        $notes = trim((string) ($data['notes'] ?? ''));

        return DB::transaction(function () use ($tithe, $date, $member, $amounts, $notes): Tithe {
            $isNew = $tithe === null;
            $tithe ??= new Tithe;
            $before = $isNew ? [] : Arr::only($tithe->getAttributes(), self::LOGGED);

            $tithe->fill([
                'tithe_date' => $date->format('Y-m-d'),
                'member_id' => $member->id,
                'notes' => $notes === '' ? null : $notes,
                ...$amounts->toColumns(),
            ]);

            if ($isNew) {
                $actorId = $this->auth->guard()->id();
                $tithe->created_by = $actorId === null ? null : (string) $actorId;
            }

            $tithe->save();
            $tithe->refresh();

            $changes = ChangeSet::between($before, Arr::only($tithe->getAttributes(), self::LOGGED));

            if ($isNew || $changes !== []) {
                $this->activityLog->record(
                    $isNew ? 'tithe.created' : 'tithe.updated',
                    $isNew
                        ? __('Recorded a tithe of :member on :date.', ['member' => $member->name, 'date' => $date->format('d/m/Y')])
                        : __('Corrected a tithe of :member on :date.', ['member' => $member->name, 'date' => $date->format('d/m/Y')]),
                    $tithe,
                    $changes,
                );
            }

            return $tithe;
        });
    }
}
