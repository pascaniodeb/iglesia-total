<?php

namespace App\Modules\Integrity\Application;

use App\Modules\Integrity\Domain\IntegrityCheck;
use InvalidArgumentException;

/**
 * The checks the modules register (from their service providers or the application's), run
 * together by the integrity center. One check per key.
 */
final class IntegrityChecks
{
    /** @var array<string, IntegrityCheck> */
    private array $checks = [];

    public function register(IntegrityCheck $check): void
    {
        if (isset($this->checks[$check->id()])) {
            throw new InvalidArgumentException("The integrity check {$check->id()} is already registered.");
        }

        $this->checks[$check->id()] = $check;
    }

    /**
     * Runs every check. A check that throws is a failed check, not a failed page: the center must
     * still say that the rest hold.
     *
     * @return list<array{id: string, title: string, passed: bool, findings: list<string>}>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $findings = $check->findings();
            } catch (\Throwable $exception) {
                $findings = [__('The check could not run: :reason', ['reason' => $exception->getMessage()])];
            }

            $results[] = ['id' => $check->id(), 'title' => $check->title(), 'passed' => $findings === [], 'findings' => $findings];
        }

        return $results;
    }
}
