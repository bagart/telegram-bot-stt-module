<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Guard;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Global in-flight cap across all chats (§9, default 4). Failure mode:
 * proceed — the FPM worker pool itself is the backstop.
 */
final class GlobalConcurrency
{
    private const KEY = 'stt:conc';

    private const TTL_SECONDS = 120;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $cap,
    ) {}

    public function acquire(): bool
    {
        if ($this->cap <= 0) {
            return true;
        }

        try {
            $current = (int) $this->cache->get(self::KEY, 0);

            if ($current >= $this->cap) {
                return false;
            }

            $this->cache->put(self::KEY, $current + 1, self::TTL_SECONDS);

            return true;
        } catch (Throwable) {
            return true;
        }
    }

    public function release(): void
    {
        try {
            $current = (int) $this->cache->get(self::KEY, 0);
            $this->cache->put(self::KEY, max(0, $current - 1), self::TTL_SECONDS);
        } catch (Throwable) {
            // counter expires on its own
        }
    }
}
