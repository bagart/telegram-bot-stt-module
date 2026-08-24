<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Support;

use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Settings\SttSettingsService;

/**
 * Settings stub: no DB. Patches are merged into a raw map so subsequent
 * get() calls observe them exactly like the real enablement row would.
 */
final class StubSettingsService extends SttSettingsService
{
    /** @var list<array<string, mixed>> */
    public array $patches = [];

    public function __construct(
        private array $raw = [],
        private readonly bool $isPrivateChat = true,
    ) {
        // bypasses the parent constructor on purpose: no container contracts needed
    }

    public function get(string $botId, int $chatId, bool $isPrivateChat): SttSettings
    {
        return SttSettings::fromArray($this->raw, $this->isPrivateChat);
    }

    public function isEnabled(string $botId, int $chatId): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function patch(string $botId, int $chatId, array $patch): void
    {
        $this->patches[] = $patch;
        $this->raw = array_merge($this->raw, $patch);
    }
}
