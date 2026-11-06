<?php

namespace App\Modules\Members\Infrastructure;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Domain\MemberRef;
use App\Modules\Members\Http\Tables\MemberListing;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Support\Str;

/**
 * The MemberDirectory contract backed by the members table.
 */
final class DatabaseMemberDirectory implements MemberDirectory
{
    public function find(string $id): ?MemberRef
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $member = Member::withTrashed()->find($id);

        return $member === null ? null : self::ref($member);
    }

    public function findMany(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), Str::isUuid(...)));

        if ($ids === []) {
            return [];
        }

        return Member::withTrashed()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (Member $member) => [$member->id => self::ref($member)])
            ->all();
    }

    public function search(string $term, int $limit = 15): array
    {
        return array_values(Member::query()
            ->search($term)
            ->orderedByName()
            ->limit($limit)
            ->get()
            ->map(self::ref(...))
            ->all());
    }

    public function idsMatching(string $term): array
    {
        if (trim($term) === '') {
            return [];
        }

        return array_values(array_map(strval(...), Member::withTrashed()->search($term)->pluck('id')->all()));
    }

    public function registryNameIndex(): array
    {
        $index = [];

        foreach (Member::query()->orderBy('id')->get(['id', 'full_name']) as $member) {
            $index[$this->normalizeName($member->full_name)][] = $member->id;
        }

        return $index;
    }

    public function normalizeName(string $name): string
    {
        return Str::lower(Str::ascii((string) preg_replace('/\s+/u', ' ', trim($name))));
    }

    private static function ref(Member $member): MemberRef
    {
        return new MemberRef($member->id, $member->full_name, MemberListing::document($member), ! $member->trashed());
    }
}
