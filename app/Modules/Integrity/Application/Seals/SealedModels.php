<?php

namespace App\Modules\Integrity\Application\Seals;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The sealed models of every module: each provider registers its own and verification checks all
 * their rows.
 */
final class SealedModels
{
    /** @var list<class-string<Model&Sealed>> */
    private array $models = [];

    /**
     * @param  class-string  ...$models
     */
    public function register(string ...$models): void
    {
        foreach ($models as $model) {
            if (! is_subclass_of($model, Model::class) || ! is_subclass_of($model, Sealed::class)) {
                throw new InvalidArgumentException("{$model} must be a model that implements ".Sealed::class.'.');
            }

            if (! in_array($model, $this->models, true)) {
                $this->models[] = $model;
            }
        }
    }

    /**
     * @return list<class-string<Model&Sealed>>
     */
    public function all(): array
    {
        return $this->models;
    }
}
