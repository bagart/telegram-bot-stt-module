<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Support;

use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Module-local metric counters (§12). Redis-backed readonly state: small
 * label→count arrays per series with a rolling TTL, self-cleaning when
 * traffic stops. Never throws — observability must not break the pipeline.
 *
 * Series:
 *   stt_total{bot_id,provider,status}   ok | empty | <error_code>
 *   stt_quota_blocked_total{bot_id}
 *   stt_latency_bucket{provider,le}     coarse 250ms..25s buckets
 *   stt_breaker{provider}               live gauge 0|1|2
 */
final class SttStats
{
    private const TTL_SECONDS = 259200; // 72h

    private const KEY_TOTAL = 'stt:m:total';

    private const KEY_QUOTA = 'stt:m:quota';

    private const KEY_LATENCY = 'stt:m:lat';

    /** @var list<int> ascending bucket upper bounds in ms */
    private const LATENCY_BUCKETS = [250, 500, 1000, 2500, 5000, 10000, 25000];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /** Bootstrapped from ModuleFactory; safe no-op outside Laravel. */
    public static function forCurrentStore(): ?self
    {
        try {
            if (! \function_exists('app') || ! app()->bound('cache')) {
                return null;
            }

            /** @var CacheRepository $repo */
            $repo = app('cache')->store();

            return new self($repo);
        } catch (Throwable) {
            return null;
        }
    }

    public function incTotal(string $botId, string $providerKey, string $status): void
    {
        $this->bump(self::KEY_TOTAL, $botId.'|'.$providerKey.'|'.$status);
    }

    public function incQuotaBlocked(string $botId): void
    {
        $this->bump(self::KEY_QUOTA, $botId);
    }

    public function recordLatency(string $providerKey, int $latencyMs): void
    {
        $bucket = self::LATENCY_BUCKETS[0];

        foreach (self::LATENCY_BUCKETS as $upper) {
            if ($latencyMs <= $upper) {
                $bucket = $upper;
                break;
            }
        }

        $this->bump(self::KEY_LATENCY, $providerKey.'|'.$bucket);
    }

    /**
     * Prometheus text-format lines appended by the host /health/metrics.
     * Empty on degraded/unavailable stores (documented degradation).
     *
     * @return list<string>
     */
    public function prometheusLines(): array
    {
        $lines = [];

        $total = $this->read(self::KEY_TOTAL);

        if ($total !== []) {
            $lines[] = '# HELP stt_total STT transcriptions by bot/provider/status.';
            $lines[] = '# TYPE stt_total counter';

            foreach ($total as $labels => $count) {
                [$botId, $provider, $status] = explode('|', $labels);
                $lines[] = sprintf('stt_total{bot_id="%s",provider="%s",status="%s"} %d', $botId, $provider, $status, $count);
            }
        }

        $quota = $this->read(self::KEY_QUOTA);

        if ($quota !== []) {
            $lines[] = '# HELP stt_quota_blocked_total STT requests refused by daily chat quota.';
            $lines[] = '# TYPE stt_quota_blocked_total counter';

            foreach ($quota as $botId => $count) {
                $lines[] = sprintf('stt_quota_blocked_total{bot_id="%s"} %d', $botId, $count);
            }
        }

        $latency = $this->read(self::KEY_LATENCY);

        if ($latency !== []) {
            $lines[] = '# HELP stt_latency_bucket STT provider latency counts per bucket (ms, non-cumulative).';
            $lines[] = '# TYPE stt_latency_bucket gauge';

            foreach ($latency as $labels => $count) {
                [$provider, $le] = explode('|', $labels);
                $lines[] = sprintf('stt_latency_bucket{provider="%s",le="%s"} %d', $provider, $le, $count);
            }
        }

        $breakerStates = $this->breakerStates();

        if ($breakerStates !== []) {
            $lines[] = '# HELP stt_breaker Provider circuit state (0 closed | 1 open | 2 half-open).';
            $lines[] = '# TYPE stt_breaker gauge';

            foreach ($breakerStates as $provider => $state) {
                $lines[] = sprintf('stt_breaker{provider="%s"} %d', $provider, $state);
            }
        }

        return $lines;
    }

    /**
     * @return array<string, int>
     */
    private function breakerStates(): array
    {
        $states = [];

        try {
            $breaker = new ProviderBreaker($this->cache);

            foreach (array_keys((new ProviderRegistry())->all()) as $presetKey) {
                $states[$presetKey] = $breaker->state($presetKey);
            }
        } catch (Throwable) {
        }

        return $states;
    }

    private function bump(string $key, string $labelSet): void
    {
        try {
            $counts = $this->read($key);
            $counts[$labelSet] = (($counts[$labelSet] ?? 0) + 1);
            $this->cache->put($key, $counts, self::TTL_SECONDS);
        } catch (Throwable) {
            // metrics loss is always acceptable
        }
    }

    /**
     * @return array<string, int>
     */
    private function read(string $key): array
    {
        try {
            $value = $this->cache->get($key);

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }
}
