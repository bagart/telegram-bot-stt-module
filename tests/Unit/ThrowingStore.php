<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use Illuminate\Contracts\Cache\Store;

/**
 * Cache store that can be toggled to throw — models the degraded-Redis
 * posture of the §9 matrix.
 */
final class ThrowingStore implements Store
{
    public bool $throwing = false;

    public function __construct(
        private readonly Store $inner,
    ) {
    }

    public function get($key): mixed
    {
        $this->maybeThrow();

        return $this->inner->get($key);
    }

    /**
     * @param  array<string, float|int|string>  $keys
     * @return iterable<string, mixed>
     */
    public function many(array $keys): iterable
    {
        $this->maybeThrow();

        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds): bool
    {
        $this->maybeThrow();

        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds): bool
    {
        $this->maybeThrow();

        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->maybeThrow();

        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1): int|bool
    {
        $this->maybeThrow();

        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        $this->maybeThrow();

        return $this->inner->forever($key, $value);
    }

    public function forget($key): bool
    {
        $this->maybeThrow();

        return $this->inner->forget($key);
    }

    public function touch($key, $seconds): bool
    {
        $this->maybeThrow();

        return method_exists($this->inner, 'touch')
            ? $this->inner->touch($key, $seconds)
            : false;
    }

    public function flush(): bool
    {
        $this->maybeThrow();

        return $this->inner->flush();
    }

    public function getPrefix(): string
    {
        return $this->inner->getPrefix();
    }

    private function maybeThrow(): void
    {
        if ($this->throwing) {
            throw new \RuntimeException('cache down');
        }
    }
}
