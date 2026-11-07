<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Domain\IsoWeek;
use App\Modules\Treasury\Domain\OfferingAmounts;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records or corrects an offering.
 *
 * The week (the offering's and the one it moves to) must be open, the date is never in the
 * future (church calendar), the category must be active (a category
 * already on the offering may stay even if it was deactivated later), the giver must be a
 * member of the registry or nobody (anonymous), and at least one amount is positive. Changes
 * are recorded in the activity log in the same transaction.
 */
final readonly class SaveOffering
{
    private const array LOGGED = ['offering_date', 'member_id', 'offering_category_id', 'concept', 'amount_ves', 'amount_usd', 'amount_cop'];

    public function __construct(
        private MemberDirectory $members,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @param  array{offering_date: string, offering_category_id: string, member_id?: string|null, concept?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(?Offering $offering, array $data): Offering
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['offering_date']);

        if ($date === false || $date > ChurchCalendar::today()) {
            throw ValidationException::withMessages(['offering_date' => __('The offering date cannot be in the future.')]);
        }

        // A closed week never changes (the database refuses it too): say it plainly, both for the
        // week the offering is in and for the week it would move to.
        foreach (array_filter([$offering === null ? null : IsoWeek::containing($offering->offering_date->toDateTimeImmutable())->key(), IsoWeek::containing($date)->key()]) as $weekKey) {
            if (WeeklyClosure::isClosed($weekKey)) {
                throw ValidationException::withMessages(['offering_date' => __('The week :week is closed: its offerings can no longer change.', ['week' => $weekKey])]);
            }
        }

        $category = OfferingCategory::query()->find($data['offering_category_id']);
        $keepsCategory = $offering !== null && $offering->offering_category_id === $category?->id;

        if ($category === null || (! $category->is_active && ! $keepsCategory)) {
            throw ValidationException::withMessages(['offering_category_id' => __('Choose an active category.')]);
        }

        $memberId = ($data['member_id'] ?? null) ?: null;
        $member = $memberId === null ? null : $this->members->find($memberId);
        $keepsMember = $offering !== null && $offering->member_id === $memberId;

        if ($memberId !== null && ($member === null || (! $member->isInRegistry && ! $keepsMember))) {
            throw ValidationException::withMessages(['member_id' => __('Choose a member of the registry or leave the offering anonymous.')]);
        }

        try {
            $amounts = OfferingAmounts::fromInput($data['amount_ves'] ?? null, $data['amount_usd'] ?? null, $data['amount_cop'] ?? null);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['amount_ves' => __('Write at least one amount greater than zero, with up to two decimals and no thousands separators.')]);
        }

        $concept = trim((string) ($data['concept'] ?? ''));

        return DB::transaction(function () use ($offering, $date, $category, $memberId, $member, $amounts, $concept): Offering {
            $isNew = $offering === null;
            $offering ??= new Offering;
            $before = $isNew ? [] : Arr::only($offering->getAttributes(), self::LOGGED);

            $offering->fill([
                'offering_date' => $date->format('Y-m-d'),
                'member_id' => $memberId,
                'offering_category_id' => $category->id,
                'concept' => $concept === '' ? null : $concept,
                ...$amounts->toColumns(),
            ]);

            if ($isNew) {
                $actorId = $this->auth->guard()->id();
                $offering->created_by = $actorId === null ? null : (string) $actorId;
            }

            $offering->save();
            $offering->refresh();

            $changes = ChangeSet::between($before, Arr::only($offering->getAttributes(), self::LOGGED));
            $giver = $member->name ?? __('anonymous');

            if ($isNew || $changes !== []) {
                $this->activityLog->record(
                    $isNew ? 'offering.created' : 'offering.updated',
                    $isNew
                        ? __('Recorded an offering of :giver on :date.', ['giver' => $giver, 'date' => $date->format('d/m/Y')])
                        : __('Corrected an offering of :giver on :date.', ['giver' => $giver, 'date' => $date->format('d/m/Y')]),
                    $offering,
                    $changes,
                );
            }

            return $offering;
        });
    }
}
