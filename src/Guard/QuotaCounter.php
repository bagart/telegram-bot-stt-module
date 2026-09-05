<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Guard;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Per-chat daily usage counter (§9). Redis-backed readonly counter state.
 * Failure mode: fail-open (allow, flag degraded) — guards protect free tiers,
 * not money or data integrity (S7).
 */
final class QuotaCounter
{
    private const KEY_PREFIX = 'stt:q';

    private const TTL_SECONDS = 172800; // 48h

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function allowed(string $botId, int $chatId, int $dailyQuota): bool
    {
        if ($dailyQuota <= 0) {
            return true;
        }

        try {
            return $this->used($botId, $chatId) < $dailyQuota;
        } catch (Throwable) {
            return true;
        }
    }

    public function increment(string $botId, int $chatId): void
    {
        try {
            $key = $this->key($botId, $chatId);
            $value = $this->cache->get($key);

            if ($value === null) {
                $this->cache->put($key, 1, self::TTL_SECONDS);

                return;
            }

            $this->cache->put($key, ((int) $value) + 1, self::TTL_SECONDS);
        } catch (Throwable) {
            // degraded cache: quota accounting lost, breaker still bounds blast radius
        }
    }

    public function usedToday(string $botId, int $chatId): int
    {
        try {
            return $this->used($botId, $chatId);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @internal exposed for tests/diagnostics */
    public function degraded(): bool
    {
        try {
            $this->cache->get(self::KEY_PREFIX.':probe');

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function used(string $botId, int $chatId): int
    {
        $value = $this->cache->get($this->key($botId, $chatId));

        return $value === null ? 0 : (int) $value;
    }

    private function key(string $botId, int $chatId): string
    {
        return sprintf('%s:%s:%d:%s', self::KEY_PREFIX, $botId, $chatId, date('Ymd'));
    }
}
