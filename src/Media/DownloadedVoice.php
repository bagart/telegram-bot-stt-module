<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Media;

/** A voice file downloaded to a temporary local path. Must be destroyed. */
final readonly class DownloadedVoice
{
    public function __construct(
        public string $path,
        public int $bytes,
        public ?string $mimeType,
        /** Telegram-side duration in seconds (from the Voice DTO). */
        public int $durationSec,
    ) {
    }

    public function destroy(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
