<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider\Dto;

/** Input of a single transcription call. The file is already on local disk. */
final readonly class SttRequest
{
    public function __construct(
        public string $audioPath,
        public string $mimeType,
        public ?int $durationSec,
        public ?string $languageHint,
        public VoiceProviderConfig $provider,
    ) {
    }
}
