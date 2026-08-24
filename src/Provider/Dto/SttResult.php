<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider\Dto;

/** Successful transcription output. */
final readonly class SttResult
{
    public function __construct(
        public string $text,
        public ?string $language,
        public ?int $durationSec,
        public string $providerKey,
        public int $latencyMs,
    ) {}
}
