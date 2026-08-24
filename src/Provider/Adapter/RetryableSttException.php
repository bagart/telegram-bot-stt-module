<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider\Adapter;

/** Internal marker for HTTP 429 with a Retry-After hint. */
final class RetryableSttException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('STT rate limited');
    }
}
