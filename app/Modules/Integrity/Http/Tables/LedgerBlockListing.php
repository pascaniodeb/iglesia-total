<?php

namespace App\Modules\Integrity\Http\Tables;

use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Tables\TableQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The blocks of the sealed ledger, newest first, searchable by period or hash and filtered by
 * kind. Read-only by nature: a block is never edited or deleted.
 */
final class LedgerBlockListing
{
    public const string DEFAULT_SORT = 'height';

    public const array KINDS = ['genesis', 'weekly', 'monthly'];

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return ['height' => 'ledger_blocks.height'];
    }

    /**
     * @return list<Closure(Builder<LedgerBlock>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->where('ledger_blocks.period_key', 'like', $pattern),
            fn (Builder $query, string $pattern) => $query->where('ledger_blocks.hash', 'like', $pattern),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return ['kind' => fn (string $value) => in_array($value, self::KINDS, true)];
    }

    /**
     * @return Builder<LedgerBlock>
     */
    public static function base(TableQuery $table): Builder
    {
        return LedgerBlock::query()
            ->when($table->facet('kind'), fn (Builder $query, string $kind) => $query->where('ledger_blocks.kind', $kind));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(LedgerBlock $block): array
    {
        return [
            'id' => $block->id,
            'height' => $block->height,
            'kind' => $block->kind,
            'period_key' => $block->period_key,
            'hash' => trim($block->hash),
            'leaves_count' => $block->leaves_count,
            'sealed_at' => $block->sealed_at->toIso8601String(),
        ];
    }
}
