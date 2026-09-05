<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider\Dto;

use BAGArt\TelegramBotStt\Provider\SttApiStyle;

/**
 * Fully resolved runtime configuration of one STT endpoint: settings choice +
 * preset constants + vault token (ConfigResolver output).
 */
final readonly class VoiceProviderConfig
{
    public function __construct(
        public string $key,
        public SttApiStyle $apiStyle,
        public string $baseUrl,
        public ?string $token,
        public ?string $model,
        public int $connectTimeoutSec,
        public int $timeoutSec,
        public int $maxResponseBytes,
        /** Target audio container when the provider rejects native ogg/opus. */
        public ?string $containerFormat = null,
    ) {
    }
}
