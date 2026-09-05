<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Guard\QuotaCounter;
use Illuminate\Cache\ArrayStore;
use PHPUnit\Framework\TestCase;

final class QuotaCounterTest extends TestCase
{
    public function test_zero_quota_is_unlimited(): void
    {
        $counter = new QuotaCounter(TestCache::repository());

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($counter->allowed('b', 1, 0));
        }
    }

    public function test_enforces_daily_limit_then_blocks(): void
    {
        $counter = new QuotaCounter(TestCache::repository());

        self::assertTrue($counter->allowed('b', 1, 2));
        $counter->increment('b', 1);
        self::assertTrue($counter->allowed('b', 1, 2));
        $counter->increment('b', 1);
        self::assertFalse($counter->allowed('b', 1, 2));
        self::assertSame(2, $counter->usedToday('b', 1));
    }

    public function test_counts_are_per_chat(): void
    {
        $counter = new QuotaCounter(TestCache::repository());
        $counter->increment('b', 1);
        $counter->increment('b', 1);

        self::assertTrue($counter->allowed('b', 2, 2));
        self::assertFalse($counter->allowed('b', 1, 2));
    }

    public function test_degraded_cache_fails_open(): void
    {
        $store = new ThrowingStore(new ArrayStore());
        $counter = new QuotaCounter(TestCache::decorate($store));

        $store->throwing = true;

        self::assertTrue($counter->allowed('b', 1, 1), 'quota must fail-open when cache is down');
    }

    public function test_increment_survives_degraded_cache(): void
    {
        $store = new ThrowingStore(new ArrayStore());
        $counter = new QuotaCounter(TestCache::decorate($store));

        $store->throwing = true;
        $counter->increment('b', 1);

        self::assertTrue($counter->degraded());
    }
}
