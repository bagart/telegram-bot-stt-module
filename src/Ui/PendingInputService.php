<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Ui;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * One-active-action-per-user store for admin input flows (token paste,
 * template editor, provider JSON editor) — Summarizer pending-input pattern
 * backed by the cache store (15-min TTL) instead of a DB table, keeping the
 * module schema at two tables (§11). Loss on cache flush is harmless.
 */
class PendingInputService
{
    public const ACTION_TOKEN = 'token_input';

    public const ACTION_TEMPLATE = 'template_input';

    public const ACTION_PROVIDER_JSON = 'provider_json';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $ttlMinutes = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function start(string $botId, int $chatId, int $userTgId, string $action, array $payload = []): void
    {
        try {
            $this->cache->put($this->key($botId, $chatId, $userTgId), [
                'action' => $action,
                'payload' => $payload,
            ], max(60, $this->ttlMinutes * 60));
        } catch (Throwable) {
            // degraded cache: text-input flows degrade, voice flows keep working
        }
    }

    /**
     * Non-destructive existence probe for processor support().
     */
    public function peek(string $botId, int $chatId, int $userTgId): bool
    {
        try {
            return $this->cache->has($this->key($botId, $chatId, $userTgId));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Atomically consume the pending action. Returns null when none/expired.
     *
     * @return array{action: string, payload: array<string, mixed>}|null
     */
    public function pop(string $botId, int $chatId, int $userTgId): ?array
    {
        try {
            $key = $this->key($botId, $chatId, $userTgId);
            $value = $this->cache->get($key);

            if (! is_array($value) || ! is_string($value['action'] ?? null)) {
                return null;
            }

            $this->cache->forget($key);

            return [
                'action' => (string) $value['action'],
                'payload' => is_array($value['payload'] ?? null) ? $value['payload'] : [],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function cancel(string $botId, int $chatId, int $userTgId): void
    {
        try {
            $this->cache->forget($this->key($botId, $chatId, $userTgId));
        } catch (Throwable) {
        }
    }

    private function key(string $botId, int $chatId, int $userTgId): string
    {
        return sprintf('stt:pending:%s:%d:%d', $botId, $chatId, $userTgId);
    }
}
