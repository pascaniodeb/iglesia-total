<?php

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Enums\AccountType;

/**
 * What another module may know about an account: enough to show it and to post to it by code.
 */
final readonly class AccountRef
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public AccountType $type,
        public bool $isActive,
    ) {}

    /**
     * @return array{id: string, code: string, name: string}
     */
    public function toOption(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name];
    }
}
