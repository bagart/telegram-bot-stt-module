<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Guard;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Per-provider circuit breaker (§9): 5 consecutive failures open the circuit
 * for 60 s, then a single probe is allowed (half-open). Failure mode on
 * degraded Redis: treat as closed + log — a dead provider still surfaces
 * through per-call errors.
 */
final class ProviderBreaker
{
    public const STATE_CLOSED = 0;

    public const STATE_OPEN = 1;

    public const STATE_HALF_OPEN = 2;

    private const FAIL_THRESHOLD = 5;

    private const OPEN_SECONDS = 60;

    private const COUNTER_TTL_SECONDS = 600;

    public function __construct(
        private readonly CacheRepository $cache,
        /** Settable so callers (orchestrator) can surface degradation once. */
        public bool $degraded = false,
    ) {
    }

    public function allow(string $providerKey): bool
    {
        try {
            return $this->cache->get($this->openKey($providerKey)) === null;
        } catch (Throwable) {
            $this->degraded = true;

            return true;
        }
    }

    /**
     * @return int 0 closed | 1 open | 2 half-open
     */
    public function state(string $providerKey): int
    {
        try {
            if ($this->cache->get($this->openKey($providerKey)) !== null) {
                return self::STATE_OPEN;
            }

            return ((int) $this->cache->get($this->failKey($providerKey), 0)) > 0
                ? self::STATE_HALF_OPEN
                : self::STATE_CLOSED;
        } catch (Throwable) {
            $this->degraded = true;

            return self::STATE_CLOSED;
        }
    }

    public function recordSuccess(string $providerKey): void
    {
        try {
            $this->cache->forget($this->failKey($providerKey));
            $this->cache->forget($this->openKey($providerKey));
        } catch (Throwable) {
            $this->degraded = true;
        }
    }

    public function recordFailure(string $providerKey): void
    {
        try {
            $fails = ((int) $this->cache->get($this->failKey($providerKey), 0)) + 1;

            if ($fails >= self::FAIL_THRESHOLD) {
                $this->cache->put($this->openKey($providerKey), 1, self::OPEN_SECONDS);
                $this->cache->forget($this->failKey($providerKey));

                return;
            }

            $this->cache->put($this->failKey($providerKey), $fails, self::COUNTER_TTL_SECONDS);
        } catch (Throwable) {
            $this->degraded = true;
        }
    }

    private function failKey(string $providerKey): string
    {
        return 'stt:brk:'.$providerKey.':fails';
    }

    private function openKey(string $providerKey): string
    {
        return 'stt:brk:'.$providerKey.':open';
    }
}
