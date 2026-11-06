<?php

namespace App\Modules\Members\Http\Tables;

use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Members\Domain\Enums\MemberDocumentType;
use App\Modules\Members\Domain\Enums\MemberSex;
use App\Modules\Members\Domain\Enums\MemberStatus;
use App\Modules\Members\Infrastructure\Models\Member;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The members listing in one place: what can be sorted, searched and filtered, and the row
 * the table shows.
 */
final class MemberListing
{
    public const string DEFAULT_SORT = 'name';

    /**
     * Sorts the listing accepts. The name sorts by the accent-insensitive search column, which
     * has an index with the key as tiebreaker.
     *
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'name' => 'members.search_name',
            'document' => 'members.document_id',
            'joined' => 'members.joined_date',
            'status' => 'members.status',
        ];
    }

    /**
     * What the search looks into. The name goes through the trigram index.
     *
     * @return list<Closure(Builder<Member>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->where('members.search_name', 'like', $pattern),
            fn (Builder $query, string $pattern) => $query->where('members.document_id', 'ilike', $pattern),
            fn (Builder $query, string $pattern) => $query->where('members.phone', 'like', $pattern),
        ];
    }

    /**
     * Validators of the filters (whitelists).
     *
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'member_status' => fn (string $value) => MemberStatus::tryFrom($value) !== null,
            'sex' => fn (string $value) => MemberSex::tryFrom($value) !== null,
        ];
    }

    /**
     * The members with the filters applied, without search or trash status (TableQuery adds
     * those when it pages).
     *
     * @return Builder<Member>
     */
    public static function base(TableQuery $table): Builder
    {
        return Member::query()
            ->when($table->facet('member_status'), fn (Builder $query, string $status) => $query->where('members.status', $status))
            ->when($table->facet('sex'), fn (Builder $query, string $sex) => $query->where('members.sex', $sex));
    }

    /**
     * A row of the table.
     *
     * @return array<string, mixed>
     */
    public static function row(Member $member): array
    {
        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document' => self::document($member),
            'phone' => $member->phone,
            'joined_date' => $member->joined_date?->toDateString(),
            'status' => $member->status?->value,
            'status_label' => $member->status?->label(),
            'is_deleted' => $member->trashed(),
        ];
    }

    /**
     * The document as people write it: "V-12345678", "Pasaporte A123", or the bare number.
     */
    public static function document(Member $member): ?string
    {
        if ($member->document_id === null) {
            return null;
        }

        return match ($member->document_type) {
            MemberDocumentType::Venezuelan, MemberDocumentType::Foreigner => "{$member->document_type->value}-{$member->document_id}",
            MemberDocumentType::Passport => "{$member->document_type->label()} {$member->document_id}",
            default => $member->document_id,
        };
    }
}
