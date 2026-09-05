<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

final class ProviderBreakerTest extends TestCase
{
    private function breaker(): ProviderBreaker
    {
        return new ProviderBreaker(TestCache::repository());
    }

    public function test_opens_after_threshold_and_recovers_via_success(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 4; $i++) {
            $breaker->recordFailure('p');
        }

        self::assertTrue($breaker->allow('p'));
        self::assertSame(ProviderBreaker::STATE_HALF_OPEN, $breaker->state('p'));

        // 5th failure trips the circuit open for 60s
        $breaker->recordFailure('p');

        self::assertFalse($breaker->allow('p'));
        self::assertSame(ProviderBreaker::STATE_OPEN, $breaker->state('p'));

        // a success after the window resets everything (probe path)
        $breaker->recordSuccess('p');

        self::assertTrue($breaker->allow('p'));
        self::assertSame(ProviderBreaker::STATE_CLOSED, $breaker->state('p'));
    }

    public function test_degraded_cache_fails_open(): void
    {
        $repo = TestCache::repository();
        $store = new ThrowingStore(new ArrayStore());
        $breaker = new ProviderBreaker(new Repository($store));

        $store->throwing = true;

        self::assertTrue($breaker->allow('p'), 'degraded cache must treat breaker as closed');
        self::assertSame(ProviderBreaker::STATE_CLOSED, $breaker->state('p'));

        // recovery paths swallow cache loss too
        $breaker->recordSuccess('p');
        $breaker->recordFailure('p');

        self::addToAssertionCount(1);
    }
}
