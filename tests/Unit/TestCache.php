<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Events\Dispatcher;

/**
 * Cache repositories for guard tests. An explicit event dispatcher is
 * mandatory: without it the Repository falls back to the app container,
 * which does not exist in standalone unit runs.
 */
final class TestCache
{
    public static function repository(): CacheRepository
    {
        return self::decorate(new ArrayStore());
    }

    public static function decorate(Store $store): CacheRepository
    {
        $repository = new Repository($store);
        $repository->setEventDispatcher(new Dispatcher());

        return $repository;
    }
}
