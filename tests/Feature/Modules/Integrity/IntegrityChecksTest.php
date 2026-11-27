<?php

use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Domain\IntegrityCheck;

function fakeCheck(string $id, array $findings = [], ?Throwable $throws = null): IntegrityCheck
{
    return new class($id, $findings, $throws) implements IntegrityCheck
    {
        /** @param list<string> $findings */
        public function __construct(private string $id, private array $findings, private ?Throwable $throws) {}

        public function id(): string
        {
            return $this->id;
        }

        public function title(): string
        {
            return "Check {$this->id}";
        }

        public function findings(): array
        {
            return $this->throws === null ? $this->findings : throw $this->throws;
        }
    };
}

test('every registered check is run and reported with its findings', function () {
    $checks = new IntegrityChecks;
    $checks->register(fakeCheck('a'));
    $checks->register(fakeCheck('b', ['Something is off.']));

    expect($checks->run())->toBe([
        ['id' => 'a', 'title' => 'Check a', 'passed' => true, 'findings' => []],
        ['id' => 'b', 'title' => 'Check b', 'passed' => false, 'findings' => ['Something is off.']],
    ]);
});

test('a check that cannot run is a failed check and the rest still run', function () {
    $checks = new IntegrityChecks;
    $checks->register(fakeCheck('broken', throws: new RuntimeException('boom')));
    $checks->register(fakeCheck('fine'));

    $results = $checks->run();

    expect($results[0]['passed'])->toBeFalse()
        ->and($results[0]['findings'][0])->toContain('boom')
        ->and($results[1]['passed'])->toBeTrue();
});

test('a key is registered once', function () {
    $checks = new IntegrityChecks;
    $checks->register(fakeCheck('a'));

    expect(fn () => $checks->register(fakeCheck('a')))->toThrow(InvalidArgumentException::class);
});
