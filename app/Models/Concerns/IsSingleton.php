<?php

namespace App\Models\Concerns;

/**
 * A settings model with exactly one row, created with its defaults on first use.
 */
trait IsSingleton
{
    /**
     * @return array<string, mixed>
     */
    abstract protected static function defaultValues(): array;

    public static function current(): static
    {
        return static::query()->find(1) ?? static::unguarded(
            fn (): static => static::query()->createOrFirst(['id' => 1], static::defaultValues())->refresh(),
        );
    }
}
