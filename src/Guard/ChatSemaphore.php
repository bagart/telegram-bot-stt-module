<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Guard;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * One in-flight transcription per chat (§9). Cache::add is an atomic
 * set-if-absent on Redis. Failure mode: skip acquire and proceed — the
 * stt_transcriptions dedupe row is the real serializer.
 */
final class ChatSemaphore
{
    private const KEY_PREFIX = 'stt:sem';

    private const LEASE_SECONDS = 60;

    /** @var list<string> held keys, released in finally */
    private array $held = [];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function acquire(string $botId, int $chatId): bool
    {
        try {
            $key = sprintf('%s:%s:%d', self::KEY_PREFIX, $botId, $chatId);

            if (! $this->cache->add($key, 1, self::LEASE_SECONDS)) {
                return false;
            }

            $this->held[] = $key;

            return true;
        } catch (Throwable) {
            return true;
        }
    }

    public function release(string $botId, int $chatId): void
    {
        try {
            $this->cache->forget(sprintf('%s:%s:%d', self::KEY_PREFIX, $botId, $chatId));
        } catch (Throwable) {
            // lease expires on its own (60 s)
        }
    }
}
