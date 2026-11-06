<?php

namespace App\Modules\Members\Domain;

/**
 * What another module may know about a member: who it is, how to tell them apart, and whether
 * they are still in the registry (not in the trash).
 */
final readonly class MemberRef
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $document,
        public bool $isInRegistry,
    ) {}

    /**
     * @return array{id: string, name: string, document: string|null}
     */
    public function toOption(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'document' => $this->document];
    }
}
