<?php

namespace App\Modules\Members\Application;

use App\Modules\Members\Domain\MemberRef;

/**
 * Public contract of the members registry for other modules (Treasury names who gave an
 * offering through it). Read-only; other modules keep only the member's id.
 */
interface MemberDirectory
{
    /**
     * A member by id, also when they are in the trash (an old offering still names its giver).
     */
    public function find(string $id): ?MemberRef;

    /**
     * Several members in one read, keyed by id (trashed included, unknown ids left out).
     *
     * @param  list<string>  $ids
     * @return array<string, MemberRef>
     */
    public function findMany(array $ids): array;

    /**
     * Members of the registry (not in the trash) whose name contains the text, accent- and
     * case-insensitive, by name.
     *
     * @return list<MemberRef>
     */
    public function search(string $term, int $limit = 15): array;

    /**
     * Ids of every member (trashed included) whose name contains the text: to filter another
     * module's listing by the giver's name.
     *
     * @return list<string>
     */
    public function idsMatching(string $term): array;

    /**
     * Members of the registry (not in the trash) by normalized full name: to match a name typed
     * in a file exactly. A list, because two people can share a name (an ambiguous match must
     * be refused, never guessed).
     *
     * @return array<string, list<string>>
     */
    public function registryNameIndex(): array;

    /**
     * The normalization registryNameIndex() uses: case, accents and repeated spaces ignored.
     */
    public function normalizeName(string $name): string;
}
